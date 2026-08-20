<?php

declare(strict_types=1);

namespace App\Service\LocalAi;

use App\Entity\TrainingSession;
use App\Exception\LocalAiException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GigaAmAsrProvider implements AsrProviderInterface, VoiceTranscriberInterface
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly RepetitionDetector $repetitionDetector,
        private readonly string $baseUrl,
        private readonly string $apiToken,
        private readonly string $model,
    ) {}

    public function modelName(): string
    {
        return $this->model;
    }

    public function transcribe(TrainingSession $session): array
    {
        $filename = $session->getAudioFilename();
        if ($filename === null) {
            throw new LocalAiException('audio_missing', 'Аудиозапись для расшифровки не найдена.');
        }
        $segments = array_map(static fn ($segment): array => [
            'id' => $segment->getId(),
            'start_ms' => $segment->getStartedAtMs(),
            'end_ms' => $segment->getEndedAtMs(),
        ], $session->getSegments()->toArray());

        return $this->transcribeSegments($filename, $segments);
    }

    public function transcribeVoice(string $audioFilename, int $durationMs): array
    {
        if ($durationMs < 250 || $durationMs > 300_000) {
            throw new LocalAiException('voice_duration_invalid', 'Голосовое сообщение должно длиться от четверти секунды до пяти минут.');
        }
        $items = $this->transcribeSegments($audioFilename, [['id' => 1, 'start_ms' => 0, 'end_ms' => $durationMs]]);
        $item = $items[0] ?? null;
        if (!is_array($item)) {
            throw new LocalAiException('asr_invalid_response', 'GigaAM не вернула расшифровку голосового сообщения.');
        }

        return ['text' => $item['text'], 'words' => $item['words'], 'pauses' => $item['pauses'], 'metrics' => $item['metrics']];
    }

    /** @param list<array{id: int|null, start_ms: int, end_ms: int}> $segments */
    private function transcribeSegments(string $filename, array $segments): array
    {
        $lastException = null;
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; ++$attempt) {
            try {
                $items = $this->requestTranscription($filename, $segments);
                foreach ($items as $item) {
                    if ($this->repetitionDetector->isLooping($item['text'])) {
                        throw new LocalAiException('asr_loop_detected', 'GigaAM зациклила расшифровку и повторяет один фрагмент.');
                    }
                }

                return $items;
            } catch (LocalAiException $exception) {
                $lastException = $exception;
            } catch (TransportExceptionInterface|\JsonException $exception) {
                $lastException = new LocalAiException('asr_unavailable', 'Локальный сервис GigaAM недоступен.', $exception);
            }

            if ($attempt < self::MAX_ATTEMPTS) {
                usleep(250_000 * $attempt);
            }
        }

        $looped = $lastException?->getErrorCode() === 'asr_loop_detected';
        throw new LocalAiException(
            $looped ? 'asr_loop_after_retries' : 'asr_failed_after_retries',
            $looped
                ? 'GigaAM трижды вернула зацикленную расшифровку. Анализ остановлен; попробуйте перезапустить его позже.'
                : 'Не удалось выполнить локальную расшифровку после трёх попыток. Проверьте, что сервис GigaAM запущен.',
            $lastException,
        );
    }

    /** @return list<array{segmentId: int, text: string, words: list<array{text: string, startMs: int, endMs: int}>, pauses: list<array{startMs: int, endMs: int, durationMs: int}>, metrics: array<string, int|float>}> */
    private function requestTranscription(string $filename, array $segments): array
    {
        $response = $this->httpClient->request('POST', rtrim($this->baseUrl, '/').'/v1/transcriptions', [
            'headers' => ['Authorization' => 'Bearer '.$this->apiToken],
            'json' => ['audio_filename' => $filename, 'model' => $this->model, 'segments' => $segments],
            'timeout' => 1_200,
        ]);
        $status = $response->getStatusCode();
        $payload = json_decode($response->getContent(false), true, 512, JSON_THROW_ON_ERROR);
        if ($status >= 400 || !is_array($payload)) {
            $message = is_array($payload) ? (string) ($payload['error'] ?? $payload['detail'] ?? '') : '';
            throw new LocalAiException('asr_request_failed', $message !== '' ? $message : 'GigaAM не смогла обработать аудиозапись.');
        }

        $rawItems = $payload['segments'] ?? null;
        if (!is_array($rawItems) || count($rawItems) !== count($segments)) {
            throw new LocalAiException('asr_invalid_response', 'GigaAM вернула неполную расшифровку вопросов.');
        }

        $expectedIds = array_column($segments, 'id');
        $items = [];
        foreach ($rawItems as $rawItem) {
            if (!is_array($rawItem)) {
                throw new LocalAiException('asr_invalid_response', 'GigaAM вернула некорректный формат расшифровки.');
            }
            $segmentId = filter_var($rawItem['segment_id'] ?? null, FILTER_VALIDATE_INT);
            if ($segmentId === false || !in_array($segmentId, $expectedIds, true)) {
                throw new LocalAiException('asr_invalid_response', 'GigaAM вернула неизвестный сегмент ответа.');
            }
            $text = trim((string) ($rawItem['text'] ?? ''));
            $items[] = [
                'segmentId' => $segmentId,
                'text' => mb_substr($text, 0, 50_000),
                'words' => $this->normalizeWords($rawItem['words'] ?? []),
                'pauses' => $this->normalizePauses($rawItem['pauses'] ?? []),
                'metrics' => $this->normalizeMetrics($rawItem['metrics'] ?? []),
            ];
        }

        return $items;
    }

    /** @return list<array{text: string, startMs: int, endMs: int}> */
    private function normalizeWords(mixed $words): array
    {
        if (!is_array($words)) {
            return [];
        }

        $normalized = [];
        foreach (array_slice($words, 0, 10_000) as $word) {
            if (!is_array($word)) continue;
            $start = max(0, (int) ($word['start_ms'] ?? 0));
            $end = max($start, (int) ($word['end_ms'] ?? $start));
            $text = trim((string) ($word['text'] ?? ''));
            if ($text !== '') $normalized[] = ['text' => mb_substr($text, 0, 100), 'startMs' => $start, 'endMs' => $end];
        }

        return $normalized;
    }

    /** @return list<array{startMs: int, endMs: int, durationMs: int}> */
    private function normalizePauses(mixed $pauses): array
    {
        if (!is_array($pauses)) return [];
        $normalized = [];
        foreach (array_slice($pauses, 0, 2_000) as $pause) {
            if (!is_array($pause)) continue;
            $start = max(0, (int) ($pause['start_ms'] ?? 0));
            $end = max($start, (int) ($pause['end_ms'] ?? $start));
            $normalized[] = ['startMs' => $start, 'endMs' => $end, 'durationMs' => $end - $start];
        }

        return $normalized;
    }

    /** @return array<string, int|float> */
    private function normalizeMetrics(mixed $metrics): array
    {
        if (!is_array($metrics)) return [];
        $allowed = ['durationMs', 'speechDurationMs', 'pauseDurationMs', 'pauseCount', 'longPauseCount', 'wordsPerMinute', 'fillerWordCount'];
        $normalized = [];
        foreach ($allowed as $key) {
            if (isset($metrics[$key]) && is_numeric($metrics[$key])) $normalized[$key] = $metrics[$key] + 0;
        }

        return $normalized;
    }
}
