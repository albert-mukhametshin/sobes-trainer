<?php

declare(strict_types=1);

namespace App\Tests\Service\LocalAi;

use App\Service\LocalAi\RepetitionDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RepetitionDetectorTest extends TestCase
{
    #[DataProvider('loopingTexts')]
    public function testDetectsModelLoops(string $text): void
    {
        self::assertTrue((new RepetitionDetector())->isLooping($text));
    }

    public function testAcceptsNormalDetailedAnswer(): void
    {
        $text = 'Контейнер зависимостей строит определения сервисов и компилирует их. Autowiring сопоставляет тип аргумента конструктора с сервисом. Если реализаций интерфейса несколько, применяется явный alias. Скалярные значения задаются через bind или параметры. Это уменьшает ручную сборку объектов и оставляет зависимости видимыми в конструкторе. В production контейнер компилируется в оптимизированный PHP-код.';

        self::assertFalse((new RepetitionDetector())->isLooping($text));
    }

    /** @return iterable<string, array{string}> */
    public static function loopingTexts(): iterable
    {
        yield 'same token' => [str_repeat('повтор ', 50)];
        yield 'same phrase' => [str_repeat('модель снова повторяет этот одинаковый фрагмент ответа ', 8)];
        yield 'same sentence' => [str_repeat('Это одно и то же длинное предложение без новой полезной информации. ', 5)];
    }
}
