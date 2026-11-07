<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;

/**
 * A label missing from a language file shows its key on the page, `blog.card.read_more`,
 * and nothing else says so.
 */
final class TranslationsTest extends TestCase
{
    /**
     * @param list<string> $sources
     */
    #[DataProvider('domains')]
    public function testEveryLabelUsedIsTranslated(string $domain, string $pattern, array $sources): void
    {
        $root = \dirname(__DIR__);
        $used = [];

        foreach ($sources as $source) {
            foreach (glob($root.'/'.$source) ?: [] as $file) {
                preg_match_all($pattern, (string) file_get_contents($file), $matches);
                $used = [...$used, ...$matches[1]];
            }
        }

        self::assertNotEmpty($used);

        foreach (glob($root.'/translations/'.$domain.'.*.yaml') ?: [] as $file) {
            $locale = explode('.', basename($file))[1];
            $catalogue = new YamlFileLoader()->load($file, $locale, $domain);

            foreach (array_unique($used) as $key) {
                self::assertTrue($catalogue->has($key, $domain), sprintf('"%s" is missing from %s.', $key, basename($file)));
            }
        }
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function domains(): iterable
    {
        yield 'labels' => ['MulerTechBlogBundle', "/'((?:blog|admin)\\.[a-z_.]+)'/", ['templates/*/*.twig', 'src/*/*.php']];
        yield 'validation messages' => ['validators', "/'(mulertech_blog\\.[a-z_.]+)'/", ['src/Form/*.php', 'src/Validator/*.php', 'src/Controller/*.php']];
    }
}
