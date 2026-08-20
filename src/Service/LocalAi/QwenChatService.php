<?php

declare(strict_types=1);

namespace App\Service\LocalAi;

use App\Exception\LocalAiException;

final class QwenChatService
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(private readonly OllamaStreamClient $ollama) {}

    /** @param list<array{role: 'user'|'assistant', content: string}> $history @return array{answer: string, model: string, attempts: int} */
    public function reply(array $history): array
    {
        $messages = [[
            'role' => 'system',
            'content' => 'Ты локальный технический ассистент для подготовки PHP backend-разработчиков к собеседованиям. Отвечай на русском языке, точно, по существу, обычным текстом без Markdown и соблюдай запрошенный объём. Если не уверен в факте, прямо обозначь неопределённость. Не повторяй абзацы и не выдумывай API. Различай регистрацию сервисов, autoconfiguration и autowiring: autowiring разрешает зависимости по типам, но не является автоматической регистрацией. В Symfony рекомендуй внедрение зависимостей, а не получение сервисов через контейнер.',
        ], ...$history];

        $lastException = null;
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; ++$attempt) {
            try {
                if ($attempt > 1) {
                    $messages[] = ['role' => 'system', 'content' => 'Предыдущая генерация зациклилась или была повреждена. Ответь короче и не повторяй фрагменты.'];
                }
                $result = $this->ollama->generate($messages, null, [
                    'temperature' => 0.2,
                    'top_p' => 0.85,
                    'repeat_penalty' => 1.08,
                    'num_ctx' => 4_096,
                    'num_predict' => 800,
                ], 120);

                return ['answer' => mb_substr($result['content'], 0, 20_000), 'model' => $this->ollama->modelName(), 'attempts' => $attempt];
            } catch (LocalAiException $exception) {
                $lastException = $exception;
                if (!in_array($exception->getErrorCode(), ['llm_loop_detected', 'llm_invalid_stream', 'llm_stream_timeout', 'llm_server_error'], true)) {
                    throw $exception;
                }
                if ($attempt < self::MAX_ATTEMPTS) {
                    usleep(300_000 * $attempt);
                }
            }
        }

        throw new LocalAiException(
            $lastException?->getErrorCode() === 'llm_loop_detected' ? 'llm_loop_after_retries' : 'llm_failed_after_retries',
            'Qwen не смогла подготовить ответ после трёх попыток.',
            $lastException,
        );
    }
}
