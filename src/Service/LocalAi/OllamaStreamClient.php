<?php

declare(strict_types=1);

namespace App\Service\LocalAi;

use App\Exception\LocalAiException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OllamaStreamClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly RepetitionDetector $repetitionDetector,
        private readonly string $baseUrl,
        private readonly string $model,
    ) {}

    public function modelName(): string
    {
        return $this->model;
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, mixed>|null $format
     * @param array<string, int|float> $options
     * @return array{content: string, totalDurationMs: int, loadDurationMs: int, promptTokens: int, outputTokens: int, doneReason: string}
     */
    public function generate(array $messages, ?array $format, array $options, int $maxDuration = 90): array
    {
        $body = [
            'model' => $this->model,
            'stream' => true,
            'think' => false,
            'keep_alive' => '10m',
            'options' => $options,
            'messages' => $messages,
        ];
        if ($format !== null) {
            $body['format'] = $format;
        }

        $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/').'/api/chat', [
            'json' => $body,
            'timeout' => min(30, $maxDuration),
            'max_duration' => $maxDuration,
        ]);
        $status = $response->getStatusCode();
        if ($status >= 400) {
            $payload = json_decode($response->getContent(false), true);
            $message = is_array($payload) ? trim((string) ($payload['error'] ?? '')) : '';
            throw new LocalAiException(
                $status >= 500 ? 'llm_server_error' : 'llm_request_failed',
                $message !== '' ? $message : 'Ollama не смогла запустить локальную модель '.$this->model.'.',
            );
        }

        $buffer = '';
        $content = '';
        $metadata = [];
        $lastLoopCheckLength = 0;

        try {
            foreach ($this->httpClient->stream($response, min(30, $maxDuration)) as $chunk) {
                if ($chunk->isTimeout()) {
                    $response->cancel();
                    throw new LocalAiException('llm_stream_timeout', 'Qwen слишком долго не возвращает новые данные.');
                }

                $buffer .= $chunk->getContent();
                while (($newline = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $newline);
                    $buffer = substr($buffer, $newline + 1);
                    $this->consumeLine($line, $content, $metadata);
                }

                if (mb_strlen($content) - $lastLoopCheckLength >= 120) {
                    $lastLoopCheckLength = mb_strlen($content);
                    if ($this->repetitionDetector->isLooping($content)) {
                        $response->cancel();
                        throw new LocalAiException('llm_loop_detected', 'Qwen зациклилась и повторяет один фрагмент.');
                    }
                }
            }
        } catch (TransportExceptionInterface $exception) {
            throw new LocalAiException('llm_unavailable', 'Локальный сервис Ollama недоступен.', $exception);
        }

        if (trim($buffer) !== '') {
            $this->consumeLine($buffer, $content, $metadata);
        }
        $content = trim($content);
        if ($content === '') {
            throw new LocalAiException('llm_empty_response', 'Qwen вернула пустой ответ.');
        }
        if ($this->repetitionDetector->isLooping($content)) {
            throw new LocalAiException('llm_loop_detected', 'Qwen зациклилась и повторяет один фрагмент.');
        }

        return [
            'content' => $content,
            'totalDurationMs' => (int) round(((int) ($metadata['total_duration'] ?? 0)) / 1_000_000),
            'loadDurationMs' => (int) round(((int) ($metadata['load_duration'] ?? 0)) / 1_000_000),
            'promptTokens' => (int) ($metadata['prompt_eval_count'] ?? 0),
            'outputTokens' => (int) ($metadata['eval_count'] ?? 0),
            'doneReason' => (string) ($metadata['done_reason'] ?? ''),
        ];
    }

    public function unload(): void
    {
        try {
            $this->httpClient->request('POST', rtrim($this->baseUrl, '/').'/api/generate', [
                'json' => ['model' => $this->model, 'keep_alive' => 0],
                'timeout' => 15,
            ])->getContent(false);
        } catch (\Throwable) {
            // Освобождение памяти выполняется best effort.
        }
    }

    /** @param array<string, mixed> $metadata */
    private function consumeLine(string $line, string &$content, array &$metadata): void
    {
        $line = trim($line);
        if ($line === '') {
            return;
        }

        try {
            $payload = json_decode($line, true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new LocalAiException('llm_invalid_stream', 'Ollama вернула повреждённый поток JSON.', $exception);
        }
        if (!is_array($payload)) {
            throw new LocalAiException('llm_invalid_stream', 'Ollama вернула некорректный поток данных.');
        }
        if (isset($payload['error'])) {
            throw new LocalAiException('llm_server_error', trim((string) $payload['error']) ?: 'Ollama завершила генерацию с ошибкой.');
        }

        $content .= (string) ($payload['message']['content'] ?? '');
        if (($payload['done'] ?? false) === true) {
            $metadata = $payload;
        }
    }
}
