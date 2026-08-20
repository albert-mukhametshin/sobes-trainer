<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AppControllerTest extends WebTestCase
{
    public function testApplicationShellIsRendered(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('title', 'Готово');
        self::assertSelectorExists('#app');
        self::assertSelectorExists('script[src="/assets/app.js"]');
        self::assertSelectorExists('link[href="/assets/app.css"]');
        self::assertSelectorExists('link[href="/assets/player.css"]');
        self::assertSelectorExists('link[href="/assets/analysis.css"]');
    }

    public function testChatPageIsRenderedWithTextAndVoiceControls(): void
    {
        $client = static::createClient();
        $client->request('GET', '/chat');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('title', 'Чат с Qwen');
        self::assertSelectorExists('#chat-messages');
        self::assertSelectorExists('#chat-input');
        self::assertSelectorExists('#voice-button[aria-pressed="false"]');
        self::assertSelectorExists('script[src="/assets/chat.js"]');
    }

    public function testChatApiRejectsInvalidHistoryWithoutStartingModel(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/api/chat/messages', ['messages' => []]);

        self::assertResponseStatusCodeSame(422);
        self::assertJsonStringEqualsJsonString('{"error":"Отправьте от 1 до 20 сообщений."}', (string) $client->getResponse()->getContent());
    }

    public function testVoiceApiRequiresAudio(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/chat/transcribe');

        self::assertResponseStatusCodeSame(422);
    }
}
