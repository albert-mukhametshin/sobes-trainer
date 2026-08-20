<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\LocalAiException;
use App\Service\AudioStorage;
use App\Service\LocalAi\OllamaStreamClient;
use App\Service\LocalAi\QwenChatService;
use App\Service\LocalAi\VoiceTranscriberInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/chat')]
final class ChatApiController extends AbstractController
{
    #[Route('/messages', name: 'api_chat_messages', methods: ['POST'])]
    public function messages(Request $request, QwenChatService $chat): JsonResponse
    {
        try {
            $data = $request->toArray();
            $history = $this->validateHistory($data['messages'] ?? null);
            $result = $chat->reply($history);

            return $this->json($result);
        } catch (LocalAiException $exception) {
            return $this->json(['error' => $exception->getMessage(), 'code' => $exception->getErrorCode()], 503);
        } catch (\Throwable $exception) {
            return $this->json(['error' => $exception->getMessage()], 422);
        }
    }

    #[Route('/transcribe', name: 'api_chat_transcribe', methods: ['POST'])]
    public function transcribe(
        Request $request,
        AudioStorage $audioStorage,
        VoiceTranscriberInterface $transcriber,
        OllamaStreamClient $ollama,
    ): JsonResponse {
        $audio = $request->files->get('audio');
        $durationMs = filter_var($request->request->get('durationMs'), FILTER_VALIDATE_INT);
        if ($audio === null || $durationMs === false) {
            return $this->json(['error' => 'Не удалось получить голосовое сообщение.'], 422);
        }

        $filename = null;
        try {
            $stored = $audioStorage->store($audio);
            $filename = $stored['filename'];
            // Перед GigaAM гарантированно освобождаем память, занятую Qwen.
            $ollama->unload();
            $result = $transcriber->transcribeVoice($filename, $durationMs);

            return $this->json(['text' => $result['text'], 'model' => $transcriber->modelName(), 'metrics' => $result['metrics']]);
        } catch (LocalAiException $exception) {
            return $this->json(['error' => $exception->getMessage(), 'code' => $exception->getErrorCode()], 503);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], 422);
        } finally {
            if ($filename !== null) {
                $audioStorage->delete($filename);
            }
        }
    }

    /** @return list<array{role: 'user'|'assistant', content: string}> */
    private function validateHistory(mixed $value): array
    {
        if (!is_array($value) || $value === [] || count($value) > 20) {
            throw new \InvalidArgumentException('Отправьте от 1 до 20 сообщений.');
        }

        $history = [];
        $totalLength = 0;
        foreach ($value as $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException('Некорректная история чата.');
            }
            $role = (string) ($item['role'] ?? '');
            $content = trim((string) ($item['content'] ?? ''));
            if (!in_array($role, ['user', 'assistant'], true) || $content === '' || mb_strlen($content) > 8_000) {
                throw new \InvalidArgumentException('Сообщение имеет некорректную роль или длину.');
            }
            $totalLength += mb_strlen($content);
            if ($totalLength > 24_000) {
                throw new \InvalidArgumentException('История чата слишком длинная. Начните новый диалог.');
            }
            $history[] = ['role' => $role, 'content' => $content];
        }
        if ($history[array_key_last($history)]['role'] !== 'user') {
            throw new \InvalidArgumentException('Последним должно быть сообщение пользователя.');
        }

        return $history;
    }
}
