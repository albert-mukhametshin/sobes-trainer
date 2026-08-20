<?php

declare(strict_types=1);

namespace App\Tests\Service\LocalAi;

use App\Entity\Question;
use App\Entity\Training;
use App\Entity\TrainingSession;
use App\Entity\TrainingSessionSegment;
use App\Entity\TrainingSessionTranscript;
use App\Exception\LocalAiException;
use App\Service\LocalAi\OllamaAnswerEvaluator;
use App\Service\LocalAi\OllamaStreamClient;
use App\Service\LocalAi\RepetitionDetector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OllamaAnswerEvaluatorTest extends TestCase
{
    public function testReturnsValidatedStructuredEvaluation(): void
    {
        $result = [
            'criteria' => [
                'technicalCorrectness' => 35,
                'completeness' => 17,
                'structure' => 12,
                'clarity' => 8,
                'pace' => 4,
                'pauses' => 4,
                'fillerWords' => 5,
            ],
            'verdict' => 'Ответ технически верный и хорошо структурирован, но пример можно сделать конкретнее.',
            'strengths' => ['Верно описана роль контейнера'],
            'recommendations' => ['Добавьте пример настройки alias для двух реализаций интерфейса'],
            'missingTopics' => ['Компиляция контейнера'],
        ];
        $client = new MockHttpClient($this->response(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
        $evaluator = $this->evaluator($client);

        $evaluation = $evaluator->evaluate(...$this->answer());

        self::assertSame(85, $evaluation['totalScore']);
        self::assertSame(1, $evaluation['attempts']);
        self::assertSame($result['criteria'], $evaluation['criteria']);
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testStopsAfterThreeLoopingResponsesWithStableErrorCode(): void
    {
        $loop = str_repeat('модель снова повторяет один и тот же фрагмент ответа ', 12);
        $client = new MockHttpClient([
            $this->response($loop),
            $this->response($loop),
            $this->response($loop),
        ]);
        $evaluator = $this->evaluator($client);

        try {
            $evaluator->evaluate(...$this->answer());
            self::fail('Ожидалась ошибка после трёх зацикленных ответов.');
        } catch (LocalAiException $exception) {
            self::assertSame('llm_loop_after_retries', $exception->getErrorCode());
            self::assertStringContainsString('трижды', $exception->getMessage());
        }

        self::assertSame(3, $client->getRequestsCount());
    }

    public function testIncompleteRecommendationIsNotReportedAsLoop(): void
    {
        $result = [
            'criteria' => ['technicalCorrectness' => 20, 'completeness' => 10, 'structure' => 8, 'clarity' => 6, 'pace' => 3, 'pauses' => 3, 'fillerWords' => 4],
            'verdict' => 'Ответ требует более подробного технического объяснения.',
            'strengths' => [], 'recommendations' => [''], 'missingTopics' => [],
        ];
        $content = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $client = new MockHttpClient([$this->response($content), $this->response($content), $this->response($content)]);

        try {
            $this->evaluator($client)->evaluate(...$this->answer());
            self::fail('Ожидалась ошибка неполного ответа.');
        } catch (LocalAiException $exception) {
            self::assertSame('llm_incomplete_after_retries', $exception->getErrorCode());
        }

        self::assertSame(3, $client->getRequestsCount());
    }

    public function testDoesNotRetryNonRecoverableHttpError(): void
    {
        $client = new MockHttpClient(new MockResponse('{"error":"model not found"}', ['http_code' => 404]));

        try {
            $this->evaluator($client)->evaluate(...$this->answer());
            self::fail('Ожидалась ошибка запроса Ollama.');
        } catch (LocalAiException $exception) {
            self::assertSame('llm_request_failed', $exception->getErrorCode());
        }

        self::assertSame(1, $client->getRequestsCount());
    }

    private function evaluator(MockHttpClient $client): OllamaAnswerEvaluator
    {
        $detector = new RepetitionDetector();
        $ollama = new OllamaStreamClient($client, $detector, 'http://ollama.test', 'qwen3.5:9b-q4_K_M');

        return new OllamaAnswerEvaluator($ollama, $detector, 'qwen3.5:9b-q4_K_M');
    }

    private function response(string $content): MockResponse
    {
        return new MockResponse(json_encode([
            'message' => ['content' => $content],
            'done' => true,
            'done_reason' => 'stop',
            'total_duration' => 10_000_000,
            'load_duration' => 1_000_000,
            'prompt_eval_count' => 100,
            'eval_count' => 50,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    }

    /** @return array{TrainingSessionSegment, TrainingSessionTranscript, null} */
    private function answer(): array
    {
        $question = new Question('symfony', 'Symfony', '#6e44ff', 'Как работает контейнер зависимостей?', 'Средний', 'Начните с определений сервисов.');
        $training = new Training('Тест');
        $training->update('Тест', [$question]);
        $session = new TrainingSession($training);
        $segment = new TrainingSessionSegment($session, $question, 0, 0, 30_000);
        $transcript = new TrainingSessionTranscript(
            $segment,
            'v3_e2e_rnnt',
            'Контейнер создаёт сервисы и через autowiring передаёт зависимости по типам аргументов конструктора.',
            [],
            [],
            ['durationMs' => 30_000, 'wordsPerMinute' => 112, 'pauseCount' => 2, 'longPauseCount' => 0, 'fillerWordCount' => 0],
        );

        return [$segment, $transcript, null];
    }
}
