<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Service;

use MulerTech\BlogBundle\Service\PostUrlGenerator;
use MulerTech\BlogBundle\Tests\Fixtures\Entity\BlogPost;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Loader\YamlFileLoader;
use Symfony\Component\Routing\RequestContext;

final class PostUrlGeneratorTest extends TestCase
{
    /**
     * Without `{_locale}` in its routes, a language passed to the router would end up as a
     * query string.
     */
    public function testASingleLanguageSiteKeepsTheLanguageOutOfItsAddresses(): void
    {
        $urls = $this->urls('routes_single_language.yaml', ['fr']);
        $post = new BlogPost()->setSlug('bienvenue')->setLocale('fr');

        self::assertSame('/blog/bienvenue', $urls->post($post));
        self::assertSame('https://example.com/blog/bienvenue', $urls->post($post, true));
        self::assertSame('https://example.com/blog', $urls->index('fr', true));
    }

    /**
     * The address of an article is in its own language, whatever the language of the page
     * linking to it.
     */
    public function testAMultilingualSiteAddressesEachArticleInItsLanguage(): void
    {
        $urls = $this->urls('routes.yaml', ['fr', 'en']);

        self::assertSame('/en/blog/welcome', $urls->post(new BlogPost()->setSlug('welcome')->setLocale('en')));
        self::assertSame('/fr/blog', $urls->index('fr'));
        self::assertSame(['fr', 'en'], $urls->locales());
    }

    /**
     * @param list<string> $locales
     */
    private function urls(string $routes, array $locales): PostUrlGenerator
    {
        $collection = new YamlFileLoader(new FileLocator(\dirname(__DIR__).'/Fixtures/config'))->load($routes);

        return new PostUrlGenerator(new UrlGenerator($collection, new RequestContext(host: 'example.com', scheme: 'https', parameters: ['_locale' => 'en'])), $locales);
    }
}
