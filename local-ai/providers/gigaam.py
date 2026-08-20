from __future__ import annotations

import gc
import re
import subprocess
import tempfile
from pathlib import Path
from typing import Any

import numpy as np
import soundfile as sf

from providers.base import Segment


SAMPLE_RATE = 16_000
CHUNK_SECONDS = 20
MIN_PAUSE_MS = 450
LONG_PAUSE_MS = 1_500
FILLER_PATTERN = re.compile(r"(?iu)(?<!\w)(?:э+|эм+|м-м+|ну|типа|короче|значит|как\s+бы)(?!\w)")


class GigaAmProvider:
    """Lazy GigaAM adapter. Replacing ASR only requires another base protocol adapter."""

    def __init__(self, model_name: str, device: str = "auto") -> None:
        self._model_name = model_name
        self._requested_device = device
        self._model: Any | None = None
        self._active_device: str | None = None

    @property
    def model_name(self) -> str:
        return self._model_name

    @property
    def loaded(self) -> bool:
        return self._model is not None

    def _preferred_device(self) -> str:
        if self._requested_device != "auto":
            return self._requested_device
        import torch

        if torch.backends.mps.is_available():
            return "mps"
        if torch.cuda.is_available():
            return "cuda"
        return "cpu"

    def _load(self, device: str | None = None) -> Any:
        if self._model is not None:
            return self._model
        import gigaam

        selected = device or self._preferred_device()
        try:
            self._model = gigaam.load_model(self._model_name, device=selected)
            self._active_device = selected
        except Exception:
            if selected == "cpu":
                raise
            self._model = gigaam.load_model(self._model_name, device="cpu")
            self._active_device = "cpu"
        return self._model

    def unload(self) -> None:
        self._model = None
        self._active_device = None
        gc.collect()
        try:
            import torch

            if torch.backends.mps.is_available():
                torch.mps.empty_cache()
            if torch.cuda.is_available():
                torch.cuda.empty_cache()
        except Exception:
            pass

    def transcribe(self, audio_path: Path, segments: list[Segment]) -> list[dict]:
        with tempfile.TemporaryDirectory(prefix="interview-asr-") as temp_dir:
            wav_path = Path(temp_dir) / "recording.wav"
            self._convert_to_wav(audio_path, wav_path)
            samples, sample_rate = sf.read(wav_path, dtype="float32", always_2d=False)
            if sample_rate != SAMPLE_RATE:
                raise RuntimeError(f"Ожидалось аудио {SAMPLE_RATE} Гц, получено {sample_rate} Гц.")
            if samples.ndim > 1:
                samples = np.mean(samples, axis=1)

            try:
                return [self._transcribe_segment(samples, segment, Path(temp_dir)) for segment in segments]
            except Exception:
                # На некоторых версиях PyTorch отдельные операции GigaAM могут не
                # поддерживаться MPS. Один раз переключаемся на CPU автоматически.
                if self._active_device == "mps":
                    self.unload()
                    self._load("cpu")
                    return [self._transcribe_segment(samples, segment, Path(temp_dir)) for segment in segments]
                raise

    def _transcribe_segment(self, samples: np.ndarray, segment: Segment, temp_dir: Path) -> dict:
        start_sample = max(0, round(segment.start_ms * SAMPLE_RATE / 1000))
        end_sample = min(len(samples), round(segment.end_ms * SAMPLE_RATE / 1000))
        segment_samples = samples[start_sample:end_sample]
        if len(segment_samples) == 0:
            return self._result(segment, "", [], [])

        words: list[dict] = []
        texts: list[str] = []
        chunk_size = CHUNK_SECONDS * SAMPLE_RATE
        for index, offset in enumerate(range(0, len(segment_samples), chunk_size)):
            chunk = segment_samples[offset : offset + chunk_size]
            if len(chunk) < SAMPLE_RATE // 4:
                continue
            chunk_path = temp_dir / f"segment-{segment.id}-{index}.wav"
            sf.write(chunk_path, chunk, SAMPLE_RATE, subtype="PCM_16")
            result = self._load().transcribe(str(chunk_path), word_timestamps=True)
            text = str(self._read(result, "text", "")).strip()
            if text:
                texts.append(text)
            chunk_offset_ms = segment.start_ms + round(offset * 1000 / SAMPLE_RATE)
            for raw_word in self._read(result, "words", []) or []:
                word_text = str(self._read(raw_word, "text", "")).strip()
                if not word_text:
                    continue
                start_ms = chunk_offset_ms + round(float(self._read(raw_word, "start", 0)) * 1000)
                end_ms = chunk_offset_ms + round(float(self._read(raw_word, "end", 0)) * 1000)
                words.append({"text": word_text, "start_ms": start_ms, "end_ms": max(start_ms, end_ms)})

        text = " ".join(texts).strip()
        pauses = self._pauses(words, segment)
        return self._result(segment, text, words, pauses)

    def _result(self, segment: Segment, text: str, words: list[dict], pauses: list[dict]) -> dict:
        duration_ms = max(0, segment.end_ms - segment.start_ms)
        pause_duration_ms = sum(item["end_ms"] - item["start_ms"] for item in pauses)
        speech_duration_ms = max(0, duration_ms - pause_duration_ms)
        return {
            "segment_id": segment.id,
            "text": text,
            "words": words,
            "pauses": pauses,
            "metrics": {
                "durationMs": duration_ms,
                "speechDurationMs": speech_duration_ms,
                "pauseDurationMs": pause_duration_ms,
                "pauseCount": len(pauses),
                "longPauseCount": sum(item["duration_ms"] >= LONG_PAUSE_MS for item in pauses),
                "wordsPerMinute": round(len(words) * 60_000 / duration_ms, 1) if duration_ms else 0,
                "fillerWordCount": len(FILLER_PATTERN.findall(text)),
            },
        }

    @staticmethod
    def _pauses(words: list[dict], segment: Segment) -> list[dict]:
        pauses: list[dict] = []
        previous_end = segment.start_ms
        for word in words:
            start_ms = int(word["start_ms"])
            if start_ms - previous_end >= MIN_PAUSE_MS:
                pauses.append({"start_ms": previous_end, "end_ms": start_ms, "duration_ms": start_ms - previous_end})
            previous_end = max(previous_end, int(word["end_ms"]))
        if segment.end_ms - previous_end >= MIN_PAUSE_MS:
            pauses.append({"start_ms": previous_end, "end_ms": segment.end_ms, "duration_ms": segment.end_ms - previous_end})
        return pauses

    @staticmethod
    def _read(value: Any, name: str, default: Any) -> Any:
        return value.get(name, default) if isinstance(value, dict) else getattr(value, name, default)

    @staticmethod
    def _convert_to_wav(source: Path, target: Path) -> None:
        process = subprocess.run(
            ["ffmpeg", "-hide_banner", "-loglevel", "error", "-y", "-i", str(source), "-ac", "1", "-ar", str(SAMPLE_RATE), str(target)],
            capture_output=True,
            text=True,
            timeout=600,
            check=False,
        )
        if process.returncode != 0 or not target.is_file():
            raise RuntimeError("Не удалось декодировать аудиозапись: "+process.stderr.strip()[:500])
