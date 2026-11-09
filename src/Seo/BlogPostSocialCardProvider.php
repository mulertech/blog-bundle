<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Seo;

use MulerTech\BlogBundle\Repository\BlogPostRepository;
use MulerTech\FileBundle\Model\FileInterface;
use MulerTech\FileBundle\Service\FileManagerInterface;
use MulerTech\SeoBundle\Model\SocialCardSource;
use MulerTech\SeoBundle\Model\SocialCardSourceProviderInterface;
use Psr\Clock\ClockInterface;

/**
 * Hands seo-bundle the featured image of an article, from which it derives the 1200x630
 * JPEG platforms show when the article is shared.
 */
final readonly class BlogPostSocialCardProvider implements SocialCardSourceProviderInterface
{
    public const string TYPE = 'blog';

    public function __construct(
        private BlogPostRepository $repository,
        private FileManagerInterface $fileManager,
        private ClockInterface $clock,
    ) {
    }

    public function getSocialCardType(): string
    {
        return self::TYPE;
    }

    /**
     * An article not visible yet, unpublished or scheduled, has no public page, so nothing
     * may expose its illustration.
     */
    public function findSocialCardSource(string $id): ?SocialCardSource
    {
        // The card route accepts any slug-like id, which a database refuses to compare
        // with an integer column.
        $post = ctype_digit($id) ? $this->repository->find((int) $id) : null;
        $cover = $post?->getFeaturedImage();

        if (null === $post || null === $cover || !$post->isVisibleAt($this->clock->now())) {
            return null;
        }

        return $this->source($cover);
    }

    /**
     * The one place an image becomes a card source, shared by the page declaring the card
     * and the route serving it: two versions computed apart would send every crawler
     * through a redirect.
     *
     * The stored filename is unique to each upload, so the version changes exactly when
     * the image is replaced.
     */
    public function source(FileInterface $cover): SocialCardSource
    {
        return new SocialCardSource(
            $this->fileManager->getAbsolutePath($cover),
            $cover->getStoredFilename().'|'.$cover->getSize(),
        );
    }
}
