<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Service;

use MulerTech\BlogBundle\Entity\AbstractBlogPost;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The public addresses of the blog, in the language of the article rather than of the
 * current request.
 *
 * The language goes into the address only on a site declaring several: its routes carry
 * `{_locale}`, while on a single-language site the router would append it as a query
 * string, `/blog/bienvenue?_locale=fr`.
 */
final readonly class PostUrlGenerator
{
    /**
     * @param list<string> $locales
     */
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private array $locales,
    ) {
    }

    public function post(AbstractBlogPost $post, bool $absolute = false): string
    {
        return $this->urlGenerator->generate(
            'mulertech_blog_show',
            ['slug' => $post->getSlug(), ...$this->locale($post->getLocale())],
            $absolute ? UrlGeneratorInterface::ABSOLUTE_URL : UrlGeneratorInterface::ABSOLUTE_PATH,
        );
    }

    public function index(string $locale, bool $absolute = false): string
    {
        return $this->urlGenerator->generate(
            'mulertech_blog_index',
            $this->locale($locale),
            $absolute ? UrlGeneratorInterface::ABSOLUTE_URL : UrlGeneratorInterface::ABSOLUTE_PATH,
        );
    }

    public function feed(string $locale, bool $absolute = false): string
    {
        return $this->urlGenerator->generate(
            'mulertech_blog_feed',
            $this->locale($locale),
            $absolute ? UrlGeneratorInterface::ABSOLUTE_URL : UrlGeneratorInterface::ABSOLUTE_PATH,
        );
    }

    /**
     * @return list<string>
     */
    public function locales(): array
    {
        return $this->locales;
    }

    /**
     * @return array{_locale?: string}
     */
    private function locale(string $locale): array
    {
        return \count($this->locales) > 1 ? ['_locale' => $locale] : [];
    }
}
