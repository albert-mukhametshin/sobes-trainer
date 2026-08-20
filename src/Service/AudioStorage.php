<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AudioStorage
{
    private const MAX_SIZE = 25 * 1024 * 1024;
    private const MIME_EXTENSIONS = [
        'audio/webm' => 'webm', 'audio/ogg' => 'ogg', 'audio/mp4' => 'm4a',
        'audio/mpeg' => 'mp3', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav',
        'application/ogg' => 'ogg', 'application/octet-stream' => 'webm',
    ];

    public function __construct(private readonly string $storageDirectory) {}

    /** @return array{filename: string, mimeType: string, size: int} */
    public function store(UploadedFile $file, ?string $oldFilename = null): array
    {
        if (!$file->isValid()) {
            throw new \InvalidArgumentException('Не удалось принять аудиофайл.');
        }

        $size = (int) ($file->getSize() ?? 0);
        if ($size < 1 || $size > self::MAX_SIZE) {
            throw new \InvalidArgumentException('Размер аудиозаписи должен быть от 1 байта до 25 МБ.');
        }

        $mimeType = strtolower(trim(explode(';', (string) ($file->getClientMimeType() ?: $file->getMimeType()))[0]));
        if (!isset(self::MIME_EXTENSIONS[$mimeType])) {
            throw new \InvalidArgumentException('Поддерживаются только аудиозаписи WebM, OGG, M4A, MP3 и WAV.');
        }

        if (!is_dir($this->storageDirectory) && !mkdir($directory = $this->storageDirectory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Не удалось подготовить хранилище аудио.');
        }

        $filename = bin2hex(random_bytes(24)).'.'.self::MIME_EXTENSIONS[$mimeType];
        $file->move($this->storageDirectory, $filename);

        if ($oldFilename !== null && preg_match('/^[a-f0-9]{48}\.[a-z0-9]+$/', $oldFilename)) {
            $oldPath = $this->storageDirectory.DIRECTORY_SEPARATOR.$oldFilename;
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
        }

        return ['filename' => $filename, 'mimeType' => $mimeType, 'size' => $size];
    }

    public function path(string $filename): string
    {
        if (!preg_match('/^[a-f0-9]{48}\.[a-z0-9]+$/', $filename)) {
            throw new \InvalidArgumentException('Некорректное имя аудиофайла.');
        }

        return $this->storageDirectory.DIRECTORY_SEPARATOR.$filename;
    }

    public function delete(string $filename): void
    {
        $path = $this->path($filename);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
