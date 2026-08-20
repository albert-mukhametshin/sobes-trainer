<?php

declare(strict_types=1);

namespace App\Service\LocalAi;

use App\Entity\QuestionEvaluationRubric;
use App\Entity\TrainingSessionSegment;
use App\Entity\TrainingSessionTranscript;

interface AnswerEvaluatorInterface
{
    /**
     * @return array{
     *     attempts: int,
     *     totalScore: int,
     *     criteria: array<string, int>,
     *     verdict: string,
     *     strengths: list<string>,
     *     recommendations: list<string>,
     *     missingTopics: list<string>
     * }
     */
    public function evaluate(TrainingSessionSegment $segment, TrainingSessionTranscript $transcript, ?QuestionEvaluationRubric $rubric): array;

    public function providerName(): string;
    public function modelName(): string;
    public function unload(): void;
}
