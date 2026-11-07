<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Service;

use MulerTech\BlogBundle\Entity\AbstractBlogPost;
use MulerTech\BlogBundle\Repository\BlogPostRepository;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Builds the slug of an article from its title, unique in its language.
 */
final readonly class SlugGenerator
{
    /**
     * Below the 255 characters of the column, keeping room for the suffix that tells two
     * articles with the same title apart.
     */
    private const int MAX_LENGTH = 240;

    public function __construct(
        private BlogPostRepository $repository,
        private SluggerInterface $slugger = new AsciiSlugger(),
    ) {
    }

    /**
     * `heberger-un-site`, then `heberger-un-site-2`, `-3`… when the language already has
     * an article by that slug.
     */
    public function generate(AbstractBlogPost $post): string
    {
        $base = $this->slugger->slug($post->getTitle(), '-', $post->getLocale())
            ->lower()
            ->truncate(self::MAX_LENGTH)
            ->trim('-')
            ->toString();

        if ('' === $base) {
            throw new \InvalidArgumentException(sprintf('The title "%s" yields no slug: it holds no letter nor digit. Give the article a title with at least one.', $post->getTitle()));
        }

        $slug = $base;

        for ($suffix = 2; $this->repository->isSlugTaken($slug, $post->getLocale(), $post->getId()); ++$suffix) {
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }
}
