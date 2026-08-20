<?php

declare(strict_types=1);

namespace App\Service\LocalAi;

interface VoiceTranscriberInterface
{
    /** @return array{text: string, words: list<array{text: string, startMs: int, endMs: int}>, pauses: list<array{startMs: int, endMs: int, durationMs: int}>, metrics: array<string, int|float>} */
    public function transcribeVoice(string $audioFilename, int $durationMs): array;

    public function modelName(): string;
}
