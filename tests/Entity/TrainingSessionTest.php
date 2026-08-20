<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Question;
use App\Entity\Training;
use App\Entity\TrainingSession;
use App\Entity\TrainingSessionSegment;
use PHPUnit\Framework\TestCase;

final class TrainingSessionTest extends TestCase
{
    public function testCompletedSessionCalculatesScoreAndUpdatesBestResult(): void
    {
        $training = new Training('Тестовая тренировка');
        $training->update('Тестовая тренировка', [
            $this->question('Первый вопрос'),
            $this->question('Второй вопрос'),
            $this->question('Третий вопрос'),
        ]);
        $session = new TrainingSession($training);

        $session->finish(185, 2);

        self::assertSame(TrainingSession::STATUS_COMPLETED, $session->getStatus());
        self::assertSame(185, $session->getElapsedSeconds());
        self::assertSame(2, $session->getAnsweredCount());
        self::assertSame(67, $session->getScore());
        self::assertSame(67, $training->getBestScore());
        self::assertNotNull($session->getFinishedAt());
    }

    public function testCancelledSessionDoesNotUpdateBestResult(): void
    {
        $training = new Training('Тестовая тренировка');
        $training->update('Тестовая тренировка', [$this->question('Вопрос')]);
        $session = new TrainingSession($training);

        $session->finish(10, 0, true);

        self::assertSame(TrainingSession::STATUS_CANCELLED, $session->getStatus());
        self::assertSame(0, $session->getScore());
        self::assertNull($training->getBestScore());
    }

    public function testSessionKeepsQuestionTimecodesAndSnapshots(): void
    {
        $question = $this->question('Что такое dependency injection?');
        $training = new Training('Тестовая тренировка');
        $training->update('Тестовая тренировка', [$question]);
        $session = new TrainingSession($training);
        $segment = new TrainingSessionSegment($session, $question, 0, 125, 4_875);

        $session->addSegment($segment);

        self::assertSame($segment, $session->getSegments()->first());
        self::assertSame(0, $segment->getPosition());
        self::assertSame(125, $segment->getStartedAtMs());
        self::assertSame(4_875, $segment->getEndedAtMs());
        self::assertSame('Что такое dependency injection?', $segment->getQuestionTitle());
        self::assertSame('PHP', $segment->getCategoryLabel());
        self::assertSame(TrainingSessionSegment::STATUS_COMPLETED, $segment->getStatus());
    }

    public function testAnalysisStateLifecycleAndErrorAreExplicit(): void
    {
        $question = $this->question('Что такое очередь сообщений?');
        $training = new Training('Тестовая тренировка');
        $training->update('Тестовая тренировка', [$question]);
        $session = new TrainingSession($training);
        $session->attachAudio('recording.webm', 'audio/webm', 1234);
        $session->finish(30, 1);

        $session->queueAnalysis();
        self::assertSame(TrainingSession::ANALYSIS_QUEUED, $session->getAnalysisStatus());
        $session->markAnalysisTranscribing();
        self::assertSame(TrainingSession::ANALYSIS_TRANSCRIBING, $session->getAnalysisStatus());
        $session->markAnalysisEvaluating();
        self::assertSame(TrainingSession::ANALYSIS_EVALUATING, $session->getAnalysisStatus());
        $session->completeAnalysis(84);
        self::assertSame(TrainingSession::ANALYSIS_COMPLETED, $session->getAnalysisStatus());
        self::assertSame(84, $session->getAnalysisScore());

        $session->queueAnalysis();
        $session->failAnalysis('llm_loop_after_retries', 'Модель трижды зациклилась.');
        self::assertSame(TrainingSession::ANALYSIS_FAILED, $session->getAnalysisStatus());
        self::assertSame('llm_loop_after_retries', $session->getAnalysisErrorCode());
        self::assertSame('Модель трижды зациклилась.', $session->getAnalysisErrorMessage());
    }

    private function question(string $title): Question
    {
        return new Question('php', 'PHP', '#7168a8', $title, 'Базовый', 'Подсказка');
    }
}
