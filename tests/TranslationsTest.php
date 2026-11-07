<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;

/**
 * A label missing from a language file shows its key on the page, `blog.card.read_more`,
 * and nothing else says so.
 */
final class TranslationsTest extends TestCase
{
    public function testEveryLabelOfTheTemplatesIsTranslated(): void
    {
        $root = \dirname(__DIR__);
        $used = [];

        foreach (glob($root.'/templates/blog/*.twig') ?: [] as $template) {
            preg_match_all("/'(blog\\.[a-z_.]+)'(?:\\|trans|,)/", (string) file_get_contents($template), $matches);
            $used = [...$used, ...$matches[1]];
        }

        self::assertNotEmpty($used);

        foreach (glob($root.'/translations/MulerTechBlogBundle.*.yaml') ?: [] as $file) {
            $locale = explode('.', basename($file))[1];
            $catalogue = new YamlFileLoader()->load($file, $locale, 'MulerTechBlogBundle');

            foreach (array_unique($used) as $key) {
                self::assertTrue($catalogue->has($key, 'MulerTechBlogBundle'), sprintf('"%s" is missing from %s.', $key, basename($file)));
            }
        }
    }
}
