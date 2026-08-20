from __future__ import annotations

import asyncio
import hmac
import logging
import os
from pathlib import Path

from fastapi import Depends, FastAPI, Header, HTTPException
from pydantic import BaseModel, Field, field_validator

from providers.base import Segment
from providers.gigaam import GigaAmProvider


class SegmentRequest(BaseModel):
    id: int = Field(gt=0)
    start_ms: int = Field(ge=0)
    end_ms: int = Field(gt=0)

    @field_validator("end_ms")
    @classmethod
    def validate_end(cls, value: int, info) -> int:
        start = info.data.get("start_ms", 0)
        if value <= start:
            raise ValueError("Конец сегмента должен быть позже начала.")
        return value


class TranscriptionRequest(BaseModel):
    audio_filename: str = Field(min_length=1, max_length=180)
    model: str = Field(min_length=1, max_length=120)
    segments: list[SegmentRequest] = Field(min_length=1, max_length=100)

    @field_validator("audio_filename")
    @classmethod
    def validate_filename(cls, value: str) -> str:
        if Path(value).name != value or value in {".", ".."}:
            raise ValueError("Разрешено только внутреннее имя аудиофайла.")
        return value


app = FastAPI(title="Interview Trainer Local ASR", docs_url="/docs")
provider = GigaAmProvider(
    model_name=os.getenv("LOCAL_ASR_MODEL", "v3_e2e_rnnt"),
    device=os.getenv("GIGAAM_DEVICE", "auto"),
)
processing_lock = asyncio.Lock()
logger = logging.getLogger("interview_trainer_asr")


def authorize(authorization: str = Header(default="")) -> None:
    expected = "Bearer "+os.getenv("LOCAL_AI_TOKEN", "local-interview-trainer")
    if not hmac.compare_digest(authorization, expected):
        raise HTTPException(status_code=401, detail="Неверный токен локального AI-сервиса.")


@app.get("/health")
def health() -> dict:
    return {"status": "ok", "provider": "gigaam", "model": provider.model_name, "model_loaded": provider.loaded}


@app.post("/v1/transcriptions", dependencies=[Depends(authorize)])
async def transcribe(request: TranscriptionRequest) -> dict:
    if request.model != provider.model_name:
        raise HTTPException(status_code=422, detail=f"Сервис настроен на модель {provider.model_name}.")

    storage = Path(os.getenv("AUDIO_STORAGE_DIR", "../var/audio")).expanduser().resolve()
    audio_path = (storage / request.audio_filename).resolve()
    if audio_path.parent != storage or not audio_path.is_file():
        raise HTTPException(status_code=404, detail="Аудиозапись не найдена в локальном хранилище.")

    segments = [Segment(item.id, item.start_ms, item.end_ms) for item in request.segments]
    if len({item.id for item in segments}) != len(segments):
        raise HTTPException(status_code=422, detail="Идентификаторы сегментов должны быть уникальны.")

    async with processing_lock:
        try:
            result = await asyncio.to_thread(provider.transcribe, audio_path, segments)
            return {"model": provider.model_name, "segments": result}
        except Exception as exception:
            logger.exception("GigaAM transcription failed")
            raise HTTPException(status_code=500, detail="GigaAM не смогла обработать аудиозапись. Подробности сохранены в журнале локального ASR-сервиса.") from exception
        finally:
            # Критическое требование: вес GigaAM освобождается до HTTP-ответа,
            # и лишь затем Symfony имеет право запустить Qwen через Ollama.
            provider.unload()
