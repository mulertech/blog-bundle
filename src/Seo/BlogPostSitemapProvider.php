<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Seo;

use MulerTech\BlogBundle\Repository\BlogPostRepository;
use MulerTech\BlogBundle\Service\PostUrlGenerator;
use MulerTech\SeoBundle\Model\SitemapUrl;
use MulerTech\SeoBundle\Model\SitemapUrlProviderInterface;

/**
 * The list of each language and every published article, each version of an article in
 * its own language.
 */
final readonly class BlogPostSitemapProvider implements SitemapUrlProviderInterface
{
    public function __construct(
        private BlogPostRepository $repository,
        private PostUrlGenerator $urls,
    ) {
    }

    public function getUrls(): iterable
    {
        foreach ($this->urls->locales() as $locale) {
            yield new SitemapUrl(loc: $this->urls->index($locale, true), priority: '0.7', changefreq: 'weekly');
        }

        foreach ($this->repository->findAllPublished() as $post) {
            yield new SitemapUrl(
                loc: $this->urls->post($post, true),
                priority: '0.6',
                changefreq: 'monthly',
                lastmod: $post->getSeoUpdatedAt() ?? $post->getSeoPublishedAt(),
            );
        }
    }
}
