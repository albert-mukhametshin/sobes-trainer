<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AudioStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AudioStorageTest extends TestCase
{
    public function testWebmAudioIsStoredUnderRandomName(): void
    {
        $directory = sys_get_temp_dir().'/interview-audio-'.bin2hex(random_bytes(6));
        $source = tempnam(sys_get_temp_dir(), 'audio-test-');
        self::assertNotFalse($source);
        file_put_contents($source, 'test-webm-audio');

        try {
            $storage = new AudioStorage($directory);
            $result = $storage->store(new UploadedFile($source, 'answer.webm', 'audio/webm', UPLOAD_ERR_OK, true));

            self::assertMatchesRegularExpression('/^[a-f0-9]{48}\.webm$/', $result['filename']);
            self::assertSame('audio/webm', $result['mimeType']);
            self::assertSame(15, $result['size']);
            self::assertFileExists($storage->path($result['filename']));
            $storage->delete($result['filename']);
            self::assertFileDoesNotExist($storage->path($result['filename']));
        } finally {
            if (is_dir($directory)) {
                foreach (glob($directory.'/*') ?: [] as $file) {
                    unlink($file);
                }
                rmdir($directory);
            }
            if (is_file($source)) {
                unlink($source);
            }
        }
    }
}
