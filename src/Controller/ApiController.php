<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Question;
use App\Entity\TrainingSessionEvaluation;
use App\Entity\TrainingSessionTranscript;
use App\Entity\Training;
use App\Entity\TrainingSession;
use App\Entity\TrainingSessionSegment;
use App\Message\AnalyzeTrainingSession;
use App\Service\AudioStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
final class ApiController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
    ) {}

    #[Route('/questions', name: 'api_questions', methods: ['GET'])]
    public function questions(Request $request): JsonResponse
    {
        $builder = $this->entityManager->getRepository(Question::class)->createQueryBuilder('q');
        $search = trim((string) $request->query->get('search', ''));
        $categories = array_values(array_filter(explode(',', (string) $request->query->get('categories', ''))));
        if ($search !== '') {
            $builder->andWhere('LOWER(q.title) LIKE LOWER(:search) OR LOWER(q.categoryLabel) LIKE LOWER(:search)')
                ->setParameter('search', '%'.$search.'%');
        }
        if ($categories !== []) {
            $builder->andWhere('q.category IN (:categories)')->setParameter('categories', $categories);
        }
        if ($request->query->get('sort') === 'weakest') {
            $builder->orderBy('q.memory', 'ASC')->addOrderBy('q.id', 'ASC');
        } else {
            $builder->orderBy('q.id', 'ASC');
        }
        $questions = $builder->getQuery()->getResult();

        return $this->json(['questions' => array_map($this->serializeQuestion(...), $questions)]);
    }

    #[Route('/trainings', name: 'api_trainings', methods: ['GET'])]
    public function trainings(): JsonResponse
    {
        $trainings = $this->entityManager->getRepository(Training::class)->createQueryBuilder('t')
            ->leftJoin('t.questions', 'q')->addSelect('q')->orderBy('t.updatedAt', 'DESC')->getQuery()->getResult();

        return $this->json(['trainings' => array_map($this->serializeTraining(...), $trainings)]);
    }

    #[Route('/trainings', name: 'api_training_create', methods: ['POST'])]
    public function createTraining(Request $request): JsonResponse
    {
        try {
            [$name, $questions] = $this->validateTrainingPayload($request->toArray());
        } catch (\Throwable $exception) {
            return $this->json(['error' => $exception->getMessage()], 422);
        }
        $training = new Training($name);
        $training->update($name, $questions);
        $this->entityManager->persist($training);
        $this->entityManager->flush();

        return $this->json(['training' => $this->serializeTraining($training)], 201);
    }

    #[Route('/trainings/{id<\d+>}', name: 'api_training_update', methods: ['PUT'])]
    public function updateTraining(int $id, Request $request): JsonResponse
    {
        $training = $this->entityManager->find(Training::class, $id);
        if (!$training instanceof Training) {
            return $this->json(['error' => 'Тренировка не найдена.'], 404);
        }
        try {
            [$name, $questions] = $this->validateTrainingPayload($request->toArray());
        } catch (\Throwable $exception) {
            return $this->json(['error' => $exception->getMessage()], 422);
        }
        $training->update($name, $questions);
        $this->entityManager->flush();

        return $this->json(['training' => $this->serializeTraining($training)]);
    }

    #[Route('/trainings/{id<\d+>}/sessions', name: 'api_session_create', methods: ['POST'])]
    public function createSession(int $id): JsonResponse
    {
        $training = $this->entityManager->find(Training::class, $id);
        if (!$training instanceof Training) {
            return $this->json(['error' => 'Тренировка не найдена.'], 404);
        }
        if ($training->getQuestions()->isEmpty()) {
            return $this->json(['error' => 'В тренировке нет вопросов.'], 422);
        }
        $session = new TrainingSession($training);
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $this->json(['session' => ['id' => $session->getId(), 'status' => $session->getStatus()]], 201);
    }

    #[Route('/sessions/{id<\d+>}', name: 'api_session_show', methods: ['GET'])]
    public function showSession(int $id): JsonResponse
    {
        $session = $this->entityManager->getRepository(TrainingSession::class)->createQueryBuilder('s')
            ->leftJoin('s.segments', 'segment')->addSelect('segment')
            ->andWhere('s.id = :id')->setParameter('id', $id)
            ->getQuery()->getOneOrNullResult();
        if (!$session instanceof TrainingSession) {
            return $this->json(['error' => 'Попытка не найдена.'], 404);
        }

        return $this->json(['session' => $this->serializeSession($session)]);
    }

    #[Route('/sessions/{id<\d+>}/audio', name: 'api_session_audio_upload', methods: ['POST'])]
    public function uploadAudio(int $id, Request $request, AudioStorage $audioStorage): JsonResponse
    {
        $session = $this->entityManager->find(TrainingSession::class, $id);
        if (!$session instanceof TrainingSession) {
            return $this->json(['error' => 'Попытка не найдена.'], 404);
        }
        $audio = $request->files->get('audio');
        if ($audio === null) {
            return $this->json(['error' => 'Добавьте аудиофайл в поле audio.'], 422);
        }
        try {
            $stored = $audioStorage->store($audio, $session->getAudioFilename());
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], 422);
        }
        $session->attachAudio($stored['filename'], $stored['mimeType'], $stored['size']);
        $this->entityManager->flush();

        return $this->json(['audio' => ['mimeType' => $stored['mimeType'], 'size' => $stored['size']]], 201);
    }

    #[Route('/sessions/{id<\d+>}/audio', name: 'api_session_audio_stream', methods: ['GET'])]
    public function streamAudio(int $id, AudioStorage $audioStorage): BinaryFileResponse|JsonResponse
    {
        return $this->audioResponse($id, $audioStorage, ResponseHeaderBag::DISPOSITION_INLINE);
    }

    #[Route('/sessions/{id<\d+>}/audio/download', name: 'api_session_audio_download', methods: ['GET'])]
    public function downloadAudio(int $id, AudioStorage $audioStorage): BinaryFileResponse|JsonResponse
    {
        return $this->audioResponse($id, $audioStorage, ResponseHeaderBag::DISPOSITION_ATTACHMENT);
    }

    private function audioResponse(int $id, AudioStorage $audioStorage, string $disposition): BinaryFileResponse|JsonResponse
    {
        $session = $this->entityManager->find(TrainingSession::class, $id);
        if (!$session instanceof TrainingSession || $session->getAudioFilename() === null) {
            return $this->json(['error' => 'Аудиозапись не найдена.'], 404);
        }
        $path = $audioStorage->path($session->getAudioFilename());
        if (!is_file($path)) {
            return $this->json(['error' => 'Аудиофайл недоступен.'], 404);
        }
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $session->getAudioMimeType() ?? 'application/octet-stream');
        $response->headers->set('Accept-Ranges', 'bytes');
        $response->setContentDisposition($disposition, 'interview-session-'.$id.'.'.pathinfo($path, PATHINFO_EXTENSION));

        return $response;
    }

    #[Route('/sessions/{id<\d+>}/complete', name: 'api_session_complete', methods: ['POST'])]
    public function completeSession(int $id, Request $request): JsonResponse
    {
        $session = $this->entityManager->find(TrainingSession::class, $id);
        if (!$session instanceof TrainingSession) {
            return $this->json(['error' => 'Попытка не найдена.'], 404);
        }
        if ($session->getStatus() !== TrainingSession::STATUS_RECORDING) {
            return $this->json(['error' => 'Попытка уже завершена.'], 409);
        }
        try {
            $data = $request->toArray();
            $elapsed = filter_var($data['elapsedSeconds'] ?? null, FILTER_VALIDATE_INT);
            $answered = filter_var($data['answeredCount'] ?? null, FILTER_VALIDATE_INT);
            if ($elapsed === false || $answered === false) {
                throw new \InvalidArgumentException('Некорректные данные завершения.');
            }
            $cancelled = (bool) ($data['cancelled'] ?? false);
            $segments = is_array($data['segments'] ?? null) ? $data['segments'] : [];
            $answeredFromSegments = $this->persistSegments($session, $segments, $elapsed, $cancelled);
            if ($segments !== []) {
                $answered = $answeredFromSegments;
            }
        } catch (\Throwable $exception) {
            return $this->json(['error' => $exception->getMessage()], 422);
        }
        $session->finish($elapsed, $answered, $cancelled);
        if (!$cancelled && $session->getAudioFilename() !== null && !$session->getSegments()->isEmpty()) {
            $session->queueAnalysis();
        }
        $this->entityManager->flush();
        if ($session->getAnalysisStatus() === TrainingSession::ANALYSIS_QUEUED) {
            $this->messageBus->dispatch(new AnalyzeTrainingSession((int) $session->getId()));
        }

        return $this->json(['session' => $this->serializeSession($session)]);
    }

    #[Route('/sessions/{id<\d+>}/analysis', name: 'api_session_analysis_retry', methods: ['POST'])]
    public function retryAnalysis(int $id): JsonResponse
    {
        $session = $this->entityManager->find(TrainingSession::class, $id);
        if (!$session instanceof TrainingSession) {
            return $this->json(['error' => 'Попытка не найдена.'], 404);
        }
        if ($session->getStatus() !== TrainingSession::STATUS_COMPLETED || $session->getAudioFilename() === null || $session->getSegments()->isEmpty()) {
            return $this->json(['error' => 'Для анализа нужна завершённая тренировка с аудиозаписью и таймкодами вопросов.'], 422);
        }
        if (in_array($session->getAnalysisStatus(), [TrainingSession::ANALYSIS_QUEUED, TrainingSession::ANALYSIS_TRANSCRIBING, TrainingSession::ANALYSIS_EVALUATING], true)) {
            return $this->json(['error' => 'Анализ уже выполняется.'], 409);
        }

        $session->queueAnalysis();
        $this->entityManager->flush();
        $this->messageBus->dispatch(new AnalyzeTrainingSession($id));

        return $this->json(['session' => $this->serializeSession($session)], 202);
    }

    /** @param list<mixed> $segments */
    private function persistSegments(TrainingSession $session, array $segments, int $elapsedSeconds, bool $cancelled): int
    {
        if ($segments === []) {
            return 0;
        }

        $questions = $session->getTraining()->getQuestions()->toArray();

        $previousEnd = 0;
        $completedCount = 0;
        foreach ($segments as $index => $data) {
            if (!is_array($data)) {
                throw new \InvalidArgumentException('Некорректный сегмент вопроса.');
            }
            $position = filter_var($data['position'] ?? null, FILTER_VALIDATE_INT);
            $questionId = filter_var($data['questionId'] ?? null, FILTER_VALIDATE_INT);
            $startedAtMs = filter_var($data['startedAtMs'] ?? null, FILTER_VALIDATE_INT);
            $endedAtMs = filter_var($data['endedAtMs'] ?? null, FILTER_VALIDATE_INT);
            $status = (string) ($data['status'] ?? TrainingSessionSegment::STATUS_COMPLETED);

            if ($position !== $index || $questionId === false || !isset($questions[$index]) || $questions[$index]->getId() !== $questionId) {
                throw new \InvalidArgumentException('Сегменты не соответствуют вопросам тренировки.');
            }
            if ($startedAtMs === false || $endedAtMs === false || $startedAtMs < $previousEnd || $endedAtMs < $startedAtMs) {
                throw new \InvalidArgumentException('Таймкоды вопросов должны идти по порядку и не пересекаться.');
            }
            if ($endedAtMs > ($elapsedSeconds * 1000) + 2500) {
                throw new \InvalidArgumentException('Таймкод выходит за пределы записи.');
            }
            if (!in_array($status, [TrainingSessionSegment::STATUS_COMPLETED, TrainingSessionSegment::STATUS_INTERRUPTED], true)) {
                throw new \InvalidArgumentException('Некорректный статус сегмента.');
            }
            if (!$cancelled && $status !== TrainingSessionSegment::STATUS_COMPLETED) {
                throw new \InvalidArgumentException('Завершённая тренировка не может содержать прерванный вопрос.');
            }

            $segment = new TrainingSessionSegment($session, $questions[$index], $position, $startedAtMs, $endedAtMs, $status);
            $session->addSegment($segment);
            if ($status === TrainingSessionSegment::STATUS_COMPLETED) {
                ++$completedCount;
            }
            $previousEnd = $endedAtMs;
        }

        return $completedCount;
    }

    /** @param array<string, mixed> $data @return array{string, list<Question>} */
    private function validateTrainingPayload(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('Название должно содержать от 1 до 120 символов.');
        }
        $ids = array_values(array_unique(array_filter($data['questionIds'] ?? [], static fn (mixed $id): bool => is_int($id) || ctype_digit((string) $id))));
        if ($ids === []) {
            throw new \InvalidArgumentException('Выберите хотя бы один вопрос.');
        }
        $questions = $this->entityManager->getRepository(Question::class)->findBy(['id' => $ids], ['id' => 'ASC']);
        if (count($questions) !== count($ids)) {
            throw new \InvalidArgumentException('Один или несколько вопросов не найдены.');
        }

        return [$name, $questions];
    }

    /** @return array<string, mixed> */
    private function serializeQuestion(Question $question): array
    {
        return [
            'id' => $question->getId(), 'category' => $question->getCategory(),
            'categoryLabel' => $question->getCategoryLabel(), 'categoryColor' => $question->getCategoryColor(),
            'title' => $question->getTitle(), 'level' => $question->getLevel(),
            'memory' => $question->getMemory(), 'repeats' => $question->getRepeats(),
            'hint' => $question->getHint(), 'lastAnsweredAt' => $question->getLastAnsweredAt()?->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeTraining(Training $training): array
    {
        $questions = array_map($this->serializeQuestion(...), $training->getQuestions()->toArray());

        return [
            'id' => $training->getId(), 'name' => $training->getName(), 'bestScore' => $training->getBestScore(),
            'estimatedMinutes' => count($questions) * 3,
            'categories' => array_values(array_unique(array_column($questions, 'categoryLabel'))),
            'questions' => $questions, 'updatedAt' => $training->getUpdatedAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeSession(TrainingSession $session): array
    {
        $segments = [];
        foreach ($session->getSegments() as $segment) {
            $transcript = $this->entityManager->getRepository(TrainingSessionTranscript::class)->findOneBy(['segment' => $segment]);
            $evaluation = $this->entityManager->getRepository(TrainingSessionEvaluation::class)->findOneBy(['segment' => $segment]);
            $segments[] = [
                'id' => $segment->getId(),
                'position' => $segment->getPosition(),
                'questionId' => $segment->getQuestion()?->getId(),
                'questionTitle' => $segment->getQuestionTitle(),
                'categoryLabel' => $segment->getCategoryLabel(),
                'startedAtMs' => $segment->getStartedAtMs(),
                'endedAtMs' => $segment->getEndedAtMs(),
                'status' => $segment->getStatus(),
                'transcript' => $transcript instanceof TrainingSessionTranscript ? [
                    'model' => $transcript->getModel(),
                    'text' => $transcript->getText(),
                    'words' => $transcript->getWords(),
                    'pauses' => $transcript->getPauses(),
                    'metrics' => $transcript->getMetrics(),
                ] : null,
                'evaluation' => $evaluation instanceof TrainingSessionEvaluation ? [
                    'provider' => $evaluation->getProvider(),
                    'model' => $evaluation->getModel(),
                    'attempts' => $evaluation->getAttempts(),
                    'totalScore' => $evaluation->getTotalScore(),
                    'criteria' => $evaluation->getCriteria(),
                    'verdict' => $evaluation->getVerdict(),
                    'strengths' => $evaluation->getStrengths(),
                    'recommendations' => $evaluation->getRecommendations(),
                    'missingTopics' => $evaluation->getMissingTopics(),
                ] : null,
            ];
        }

        return [
            'id' => $session->getId(),
            'trainingId' => $session->getTraining()->getId(),
            'status' => $session->getStatus(),
            'score' => $session->getScore(),
            'elapsedSeconds' => $session->getElapsedSeconds(),
            'answeredCount' => $session->getAnsweredCount(),
            'hasAudio' => $session->getAudioFilename() !== null,
            'audioUrl' => $session->getAudioFilename() !== null ? '/api/sessions/'.$session->getId().'/audio' : null,
            'downloadUrl' => $session->getAudioFilename() !== null ? '/api/sessions/'.$session->getId().'/audio/download' : null,
            'analysis' => [
                'status' => $session->getAnalysisStatus(),
                'score' => $session->getAnalysisScore(),
                'error' => $session->getAnalysisErrorCode() === null ? null : [
                    'code' => $session->getAnalysisErrorCode(),
                    'message' => $session->getAnalysisErrorMessage(),
                ],
                'startedAt' => $session->getAnalysisStartedAt()?->format(DATE_ATOM),
                'completedAt' => $session->getAnalysisCompletedAt()?->format(DATE_ATOM),
            ],
            'segments' => $segments,
        ];
    }
}
