from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
from typing import Protocol


@dataclass(frozen=True)
class Segment:
    id: int
    start_ms: int
    end_ms: int


class SpeechRecognitionProvider(Protocol):
    @property
    def model_name(self) -> str: ...

    @property
    def loaded(self) -> bool: ...

    def transcribe(self, audio_path: Path, segments: list[Segment]) -> list[dict]: ...

    def unload(self) -> None: ...
