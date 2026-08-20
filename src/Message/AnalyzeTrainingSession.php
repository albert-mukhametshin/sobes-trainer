<?php

declare(strict_types=1);

namespace App\Message;

final readonly class AnalyzeTrainingSession
{
    public function __construct(public int $sessionId) {}
}
