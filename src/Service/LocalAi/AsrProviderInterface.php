<?php

declare(strict_types=1);

namespace App\Service\LocalAi;

use App\Entity\TrainingSession;

interface AsrProviderInterface
{
    /**
     * @return list<array{
     *     segmentId: int,
     *     text: string,
     *     words: list<array{text: string, startMs: int, endMs: int}>,
     *     pauses: list<array{startMs: int, endMs: int, durationMs: int}>,
     *     metrics: array<string, int|float>
     * }>
     */
    public function transcribe(TrainingSession $session): array;

    public function modelName(): string;
}
