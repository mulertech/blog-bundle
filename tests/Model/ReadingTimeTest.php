<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Model;

use MulerTech\BlogBundle\Model\ReadingTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadingTimeTest extends TestCase
{
    #[DataProvider('texts')]
    public function testTheMinutesFollowTheWordCount(string $markdown, int $minutes): void
    {
        self::assertSame($minutes, ReadingTime::minutes($markdown));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function texts(): iterable
    {
        yield 'empty' => ['', 0];
        yield 'markup only' => ["# \n\n---\n\n> ", 0];
        yield 'a single word takes a minute' => ['Bonjour', 1];
        yield 'exactly two hundred words' => [str_repeat('mot ', 200), 1];
        yield 'one word more' => [str_repeat('mot ', 201), 2];
        yield 'accented words count once' => [str_repeat('été ', 201), 2];
        yield 'an elision is one word' => [str_repeat("l'hébergement ", 201), 2];
    }

    /**
     * A long address would otherwise add a dozen words nobody reads.
     */
    public function testTheAddressOfALinkIsNotRead(): void
    {
        self::assertSame(
            ReadingTime::minutes(str_repeat('mot ', 199).'[site]'),
            ReadingTime::minutes(str_repeat('mot ', 199).'[site](https://www.example.com/un/chemin/tres/long/avec/beaucoup/de/segments)'),
        );
        self::assertSame(1, ReadingTime::minutes(str_repeat('mot ', 199).'![schéma](/images/a/b/c/d/e/f/g.png)'));
    }
}
