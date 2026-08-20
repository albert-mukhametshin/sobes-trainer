<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\QuestionEvaluationRubric;
use App\Entity\TrainingSession;
use App\Entity\TrainingSessionEvaluation;
use App\Entity\TrainingSessionSegment;
use App\Entity\TrainingSessionTranscript;
use App\Exception\LocalAiException;
use App\Message\AnalyzeTrainingSession;
use App\Service\LocalAi\AnswerEvaluatorInterface;
use App\Service\LocalAi\AsrProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class AnalyzeTrainingSessionHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AsrProviderInterface $asrProvider,
        private AnswerEvaluatorInterface $answerEvaluator,
    ) {}

    public function __invoke(AnalyzeTrainingSession $message): void
    {
        $session = $this->entityManager->find(TrainingSession::class, $message->sessionId);
        if (!$session instanceof TrainingSession || $session->getAnalysisStatus() !== TrainingSession::ANALYSIS_QUEUED) {
            return;
        }

        try {
            // Даже если предыдущий процесс был прерван, не позволяем оставшемуся
            // в Ollama весу Qwen пересечься в памяти с запуском GigaAM.
            $this->answerEvaluator->unload();
            $transcripts = $this->loadCachedTranscripts($session);
            if (count($transcripts) !== $session->getSegments()->count()) {
                $this->removeResultsForRetranscription($session);
                $session->markAnalysisTranscribing();
                $this->entityManager->flush();

                // GigaAM загружается внутри локального ASR-сервиса и гарантированно
                // выгружается до того, как этот вызов вернёт управление.
                $transcribedSegments = $this->asrProvider->transcribe($session);
                $transcripts = $this->persistTranscripts($session, $transcribedSegments);
            }

            $session->markAnalysisEvaluating();
            $this->entityManager->flush();

            // Qwen запускается только после полного завершения и выгрузки ASR.
            $scores = [];
            foreach ($session->getSegments() as $segment) {
                $transcript = $transcripts[$segment->getId()] ?? null;
                if (!$transcript instanceof TrainingSessionTranscript) {
                    throw new LocalAiException('transcript_missing', 'Для одного из вопросов не удалось сохранить расшифровку.');
                }
                $existingEvaluation = $this->entityManager->getRepository(TrainingSessionEvaluation::class)->findOneBy(['segment' => $segment]);
                if ($existingEvaluation instanceof TrainingSessionEvaluation
                    && $existingEvaluation->getProvider() === $this->answerEvaluator->providerName()
                    && $existingEvaluation->getModel() === $this->answerEvaluator->modelName()) {
                    $scores[] = $existingEvaluation->getTotalScore();
                    continue;
                }
                if ($existingEvaluation instanceof TrainingSessionEvaluation) {
                    $this->entityManager->remove($existingEvaluation);
                    $this->entityManager->flush();
                }

                $rubric = $segment->getQuestion() === null ? null : $this->entityManager->getRepository(QuestionEvaluationRubric::class)->findOneBy(['question' => $segment->getQuestion()]);
                $result = $this->answerEvaluator->evaluate($segment, $transcript, $rubric instanceof QuestionEvaluationRubric ? $rubric : null);
                $evaluation = new TrainingSessionEvaluation(
                    $segment,
                    $this->answerEvaluator->providerName(),
                    $this->answerEvaluator->modelName(),
                    $result['attempts'],
                    $result['totalScore'],
                    $result['criteria'],
                    $result['verdict'],
                    $result['strengths'],
                    $result['recommendations'],
                    $result['missingTopics'],
                );
                $this->entityManager->persist($evaluation);
                $this->entityManager->flush();
                $scores[] = $result['totalScore'];
            }

            $session->completeAnalysis($scores === [] ? 0 : (int) round(array_sum($scores) / count($scores)));
            $this->entityManager->flush();
        } catch (LocalAiException $exception) {
            $session->failAnalysis($exception->getErrorCode(), $exception->getMessage());
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $session->failAnalysis('analysis_internal_error', 'Внутренняя ошибка локального анализа. Проверьте журналы worker и повторите попытку.');
            $this->entityManager->flush();
            throw $exception;
        } finally {
            $this->answerEvaluator->unload();
        }
    }

    /** @return array<int, TrainingSessionTranscript> */
    private function loadCachedTranscripts(TrainingSession $session): array
    {
        $transcripts = [];
        foreach ($session->getSegments() as $segment) {
            $transcript = $this->entityManager->getRepository(TrainingSessionTranscript::class)->findOneBy(['segment' => $segment]);
            if (!$transcript instanceof TrainingSessionTranscript || $transcript->getModel() !== $this->asrProvider->modelName()) {
                return [];
            }
            $transcripts[(int) $segment->getId()] = $transcript;
        }

        return $transcripts;
    }

    private function removeResultsForRetranscription(TrainingSession $session): void
    {
        foreach ($session->getSegments() as $segment) {
            $transcript = $this->entityManager->getRepository(TrainingSessionTranscript::class)->findOneBy(['segment' => $segment]);
            $evaluation = $this->entityManager->getRepository(TrainingSessionEvaluation::class)->findOneBy(['segment' => $segment]);
            if ($evaluation instanceof TrainingSessionEvaluation) $this->entityManager->remove($evaluation);
            if ($transcript instanceof TrainingSessionTranscript) $this->entityManager->remove($transcript);
        }
        $this->entityManager->flush();
    }

    /**
     * @param list<array{segmentId: int, text: string, words: list<array{text: string, startMs: int, endMs: int}>, pauses: list<array{startMs: int, endMs: int, durationMs: int}>, metrics: array<string, int|float>}> $items
     * @return array<int, TrainingSessionTranscript>
     */
    private function persistTranscripts(TrainingSession $session, array $items): array
    {
        $segments = [];
        foreach ($session->getSegments() as $segment) {
            $segments[$segment->getId()] = $segment;
        }

        $transcripts = [];
        foreach ($items as $item) {
            $segment = $segments[$item['segmentId']] ?? null;
            if (!$segment instanceof TrainingSessionSegment || isset($transcripts[$item['segmentId']])) {
                throw new LocalAiException('asr_invalid_response', 'Расшифровка содержит неизвестный или повторяющийся сегмент.');
            }
            $transcript = new TrainingSessionTranscript(
                $segment,
                $this->asrProvider->modelName(),
                $item['text'],
                $item['words'],
                $item['pauses'],
                $item['metrics'],
            );
            $this->entityManager->persist($transcript);
            $transcripts[$item['segmentId']] = $transcript;
        }

        if (count($transcripts) !== count($segments)) {
            throw new LocalAiException('asr_incomplete_response', 'GigaAM расшифровала не все вопросы тренировки.');
        }
        $this->entityManager->flush();

        return $transcripts;
    }
}
