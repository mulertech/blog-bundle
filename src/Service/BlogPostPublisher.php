<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use MulerTech\BlogBundle\Entity\AbstractBlogPost;
use Psr\Clock\ClockInterface;

final readonly class BlogPostPublisher
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * An article published before keeps its date of first publication.
     */
    public function publish(AbstractBlogPost $post): void
    {
        $post->publish($this->clock->now());
        $this->entityManager->flush();
    }

    public function unpublish(AbstractBlogPost $post): void
    {
        $post->unpublish();
        $this->entityManager->flush();
    }
}
