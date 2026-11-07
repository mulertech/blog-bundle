<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Service;

use MulerTech\BlogBundle\Service\BlogPostPublisher;
use MulerTech\BlogBundle\Tests\DatabaseTestCase;
use Symfony\Component\Clock\MockClock;

final class BlogPostPublisherTest extends DatabaseTestCase
{
    public function testPublishingSavesTheArticleWithTheCurrentDate(): void
    {
        $post = $this->post('Brouillon', publishedAt: null);
        $clock = new MockClock('2026-05-04 08:30');

        new BlogPostPublisher($this->entityManager, $clock)->publish($post);
        $this->entityManager->clear();

        self::assertSame(
            ['is_published' => 1, 'published_at' => '2026-05-04 08:30:00'],
            $this->entityManager->getConnection()->fetchAssociative('SELECT is_published, published_at FROM blog_posts WHERE id = ?', [$post->getId()]),
        );
    }

    public function testUnpublishingThenPublishingAgainKeepsTheFirstDate(): void
    {
        $post = $this->post('Article', publishedAt: '2026-01-01 10:00');
        $publisher = new BlogPostPublisher($this->entityManager, new MockClock('2026-05-04 08:30'));

        $publisher->unpublish($post);
        self::assertFalse($post->isPublished());

        $publisher->publish($post);
        self::assertEquals(new \DateTimeImmutable('2026-01-01 10:00'), $post->getPublishedAt());
    }
}
