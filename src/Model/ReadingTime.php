<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Model;

/**
 * Minutes needed to read a Markdown text, at about 200 words a minute.
 */
final class ReadingTime
{
    public const int WORDS_PER_MINUTE = 200;

    public static function minutes(string $markdown): int
    {
        // The address of a link or an image is not read, its text is.
        $text = (string) preg_replace('/!?\[([^\]]*)\]\([^)]*\)/u', '$1', $markdown);
        $words = preg_match_all("/[\\p{L}\\p{N}]+(?:['’-][\\p{L}\\p{N}]+)*/u", $text);

        if (0 === $words || false === $words) {
            return 0;
        }

        return (int) ceil($words / self::WORDS_PER_MINUTE);
    }
}
