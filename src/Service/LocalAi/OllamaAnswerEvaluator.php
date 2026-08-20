<?php

declare(strict_types=1);

namespace App\Service\LocalAi;

use App\Entity\QuestionEvaluationRubric;
use App\Entity\TrainingSessionSegment;
use App\Entity\TrainingSessionTranscript;
use App\Exception\LocalAiException;

final class OllamaAnswerEvaluator implements AnswerEvaluatorInterface
{
    private const MAX_ATTEMPTS = 3;
    private const LIMITS = [
        'technicalCorrectness' => 40,
        'completeness' => 20,
        'structure' => 15,
        'clarity' => 10,
        'pace' => 5,
        'pauses' => 5,
        'fillerWords' => 5,
    ];

    public function __construct(
        private readonly OllamaStreamClient $ollama,
        private readonly RepetitionDetector $repetitionDetector,
        private readonly string $model,
    ) {}

    public function providerName(): string { return 'ollama'; }
    public function modelName(): string { return $this->model; }

    public function evaluate(TrainingSessionSegment $segment, TrainingSessionTranscript $transcript, ?QuestionEvaluationRubric $rubric): array
    {
        $lastException = null;
        $previousErrorCode = null;
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; ++$attempt) {
            try {
                $response = $this->ollama->generate(
                    [
                        ['role' => 'system', 'content' => $this->systemPrompt()],
                        ['role' => 'user', 'content' => $this->userPrompt($segment, $transcript, $rubric, $attempt, $previousErrorCode)],
                    ],
                    $this->schema(),
                    [
                        'temperature' => 0,
                        'top_p' => 0.8,
                        'repeat_penalty' => 1.08,
                        'num_ctx' => 4_096,
                        'num_predict' => 450,
                    ],
                    90,
                );
                $result = json_decode($response['content'], true, 128, JSON_THROW_ON_ERROR);
                if (!is_array($result)) {
                    throw new LocalAiException('llm_invalid_response', 'Qwen вернула некорректный результат оценки.');
                }

                return $this->validateResult($result, $attempt);
            } catch (LocalAiException $exception) {
                $lastException = $exception;
            } catch (\JsonException $exception) {
                $lastException = new LocalAiException('llm_invalid_response', 'Qwen вернула некорректный JSON.', $exception);
            }

            if (!$this->isRetryable($lastException->getErrorCode())) {
                throw $lastException;
            }
            $previousErrorCode = $lastException->getErrorCode();
            if ($attempt < self::MAX_ATTEMPTS) {
                usleep(300_000 * $attempt);
            }
        }

        $lastCode = $lastException?->getErrorCode();
        $looped = $lastCode === 'llm_loop_detected';
        $incomplete = $lastCode === 'llm_incomplete_response';
        throw new LocalAiException(
            $looped ? 'llm_loop_after_retries' : ($incomplete ? 'llm_incomplete_after_retries' : 'llm_failed_after_retries'),
            $looped
                ? 'Qwen3.5 трижды зациклилась при оценке ответа. Анализ остановлен; перезапустите Ollama и повторите анализ.'
                : ($incomplete
                    ? 'Qwen3.5 трижды вернула неполную оценку. Повторите анализ позже.'
                    : 'Qwen3.5 не смогла вернуть корректную оценку после трёх попыток. Проверьте Ollama и наличие модели '.$this->model.'.'),
            $lastException,
        );
    }

    public function unload(): void
    {
        $this->ollama->unload();
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Ты строгий, но полезный интервьюер для PHP backend-разработчика. Оценивай только по данным вопроса, эталону, обязательным пунктам, расшифровке и речевым метрикам. Не выдумывай произнесённые факты. Пустой или нерелевантный ответ должен получить низкую оценку. Верни только JSON по переданной схеме. Не повторяй предложения, рекомендации или пункты списков.
Баллы должны строго соответствовать вердикту и только реально произнесённому тексту. Нельзя начислять баллы за сведения, которые есть в эталоне, но отсутствуют в расшифровке. При критической фактической ошибке technicalCorrectness не выше 15. Если ответ не раскрывает вопрос или повторяет его формулировку, technicalCorrectness и completeness не выше 5. Если названа менее половины обязательных пунктов, completeness не выше 10. Высокие баллы запрещены, если в вердикте ответ назван неверным, неполным или нерелевантным.
PROMPT;
    }

    private function userPrompt(TrainingSessionSegment $segment, TrainingSessionTranscript $transcript, ?QuestionEvaluationRubric $rubric, int $attempt, ?string $previousErrorCode = null): string
    {
        $reference = $rubric?->getReferenceAnswer() ?? 'Отдельный эталон не задан. Оценивай осторожно по формулировке вопроса.';
        $required = $rubric?->getRequiredPoints() ?? [];
        $mistakes = $rubric?->getCommonMistakes() ?? [];
        $metrics = json_encode($transcript->getMetrics(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $pauses = json_encode($transcript->getPauses(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $reason = match ($previousErrorCode) {
            'llm_loop_detected' => 'предыдущий ответ повторял один фрагмент',
            'llm_incomplete_response' => 'в предыдущем ответе не было содержательной рекомендации',
            default => 'предыдущий ответ не прошёл проверку формата',
        };
        $retry = $attempt > 1 ? "\nЭто повторная попытка №{$attempt}: {$reason}. Исправь только эту проблему, пиши кратко и без повторов." : '';

        return sprintf(
            "Вопрос: %s\nКатегория: %s\nЭталон: %s\nОбязательные пункты: %s\nТипичные ошибки: %s\nРасшифровка ответа: %s\nРечевые метрики: %s\nВсе паузы с абсолютными таймкодами в миллисекундах: %s\n\nШкала: техническая корректность 0–40, полнота 0–20, структура 0–15, ясность 0–10, темп 0–5, паузы 0–5, слова-паразиты 0–5. Сначала сравни расшифровку с обязательными пунктами, затем выставь баллы. Проверь, что баллы не противоречат вердикту. Сумма критериев должна составить итог от 0 до 100.%s",
            $segment->getQuestionTitle(),
            $segment->getCategoryLabel(),
            $reference,
            implode('; ', $required) ?: 'не заданы',
            implode('; ', $mistakes) ?: 'не заданы',
            $transcript->getText() !== '' ? $transcript->getText() : '[ответ не распознан]',
            $metrics,
            $pauses,
            $retry,
        );
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        $scoreProperties = [];
        foreach (self::LIMITS as $name => $maximum) {
            $scoreProperties[$name] = ['type' => 'integer', 'minimum' => 0, 'maximum' => $maximum];
        }

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'criteria' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => $scoreProperties,
                    'required' => array_keys(self::LIMITS),
                ],
                'verdict' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 500],
                'strengths' => ['type' => 'array', 'maxItems' => 3, 'items' => ['type' => 'string', 'minLength' => 5, 'maxLength' => 220]],
                'recommendations' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 3, 'items' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 220]],
                'missingTopics' => ['type' => 'array', 'maxItems' => 3, 'items' => ['type' => 'string', 'minLength' => 5, 'maxLength' => 220]],
            ],
            'required' => ['criteria', 'verdict', 'strengths', 'recommendations', 'missingTopics'],
        ];
    }

    /** @param array<string, mixed> $result @return array{attempts: int, totalScore: int, criteria: array<string, int>, verdict: string, strengths: list<string>, recommendations: list<string>, missingTopics: list<string>} */
    private function validateResult(array $result, int $attempt): array
    {
        if (!is_array($result['criteria'] ?? null)) {
            throw new LocalAiException('llm_invalid_response', 'Qwen не вернула оценки по критериям.');
        }
        $criteria = [];
        foreach (self::LIMITS as $name => $maximum) {
            $value = filter_var($result['criteria'][$name] ?? null, FILTER_VALIDATE_INT);
            if ($value === false || $value < 0 || $value > $maximum) {
                throw new LocalAiException('llm_invalid_response', 'Qwen вернула оценку вне допустимой шкалы.');
            }
            $criteria[$name] = $value;
        }

        $verdict = trim((string) ($result['verdict'] ?? ''));
        $strengths = $this->stringList($result['strengths'] ?? null, 3);
        $recommendations = $this->stringList($result['recommendations'] ?? null, 3);
        $missingTopics = $this->stringList($result['missingTopics'] ?? null, 3);
        $combined = implode(' ', [$verdict, ...$strengths, ...$recommendations, ...$missingTopics]);
        if (mb_strlen($verdict) < 10 || $recommendations === []) {
            throw new LocalAiException('llm_incomplete_response', 'Qwen вернула неполный текст оценки.');
        }
        if ($this->repetitionDetector->isLooping($combined)) {
            throw new LocalAiException('llm_loop_detected', 'Qwen вернула повторяющийся текст оценки.');
        }

        return [
            'attempts' => $attempt,
            'totalScore' => array_sum($criteria),
            'criteria' => $criteria,
            'verdict' => mb_substr($verdict, 0, 1_000),
            'strengths' => $strengths,
            'recommendations' => $recommendations,
            'missingTopics' => $missingTopics,
        ];
    }

    /** @return list<string> */
    private function stringList(mixed $value, int $limit): array
    {
        if (!is_array($value)) return [];
        $items = [];
        foreach (array_slice($value, 0, $limit) as $item) {
            $item = trim((string) $item);
            if ($item !== '' && !in_array(mb_strtolower($item), array_map('mb_strtolower', $items), true)) {
                $items[] = mb_substr($item, 0, 400);
            }
        }

        return $items;
    }

    private function isRetryable(string $code): bool
    {
        return in_array($code, [
            'llm_loop_detected',
            'llm_incomplete_response',
            'llm_invalid_response',
            'llm_empty_response',
            'llm_invalid_stream',
            'llm_stream_timeout',
            'llm_server_error',
        ], true);
    }
}
