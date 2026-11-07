<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Entity;

use MulerTech\BlogBundle\Tests\Fixtures\Entity\BlogPost;
use MulerTech\BlogBundle\Tests\Fixtures\Entity\File;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class AbstractBlogPostTest extends TestCase
{
    public function testANewArticleIsADraftWithNothingCounted(): void
    {
        $post = new BlogPost();

        self::assertFalse($post->isPublished());
        self::assertNull($post->getPublishedAt());
        self::assertSame(0, $post->getReadingTime());
        self::assertSame(0, $post->getViewCount());
        self::assertNull($post->getId());
        self::assertNull($post->getFeaturedImage());
        self::assertCount(0, $post->getFiles());
    }

    public function testPublishingRecordsTheDate(): void
    {
        $at = new \DateTimeImmutable('2026-03-01 09:00');

        $post = new BlogPost()->publish($at);

        self::assertTrue($post->isPublished());
        self::assertSame($at, $post->getPublishedAt());
    }

    /**
     * A correction made by unpublishing then publishing again must not move the article
     * to the top of the list.
     */
    public function testPublishingAgainKeepsTheDateOfFirstPublication(): void
    {
        $first = new \DateTimeImmutable('2026-03-01 09:00');

        $post = new BlogPost()->publish($first)->unpublish();

        self::assertFalse($post->isPublished());
        self::assertSame($first, $post->getPublishedAt());

        $post->publish(new \DateTimeImmutable('2026-04-15 18:30'));

        self::assertTrue($post->isPublished());
        self::assertSame($first, $post->getPublishedAt());
    }

    /**
     * A single-language site never links articles, yet every row needs a group: each
     * article starts in one of its own.
     */
    public function testEachArticleStartsInATranslationGroupOfItsOwn(): void
    {
        $first = new BlogPost();
        $second = new BlogPost();

        self::assertTrue(Uuid::isValid($first->getTranslationGroup()));
        self::assertNotSame($first->getTranslationGroup(), $second->getTranslationGroup());
    }

    public function testATranslationJoinsTheGroupOfItsOriginal(): void
    {
        $original = new BlogPost()->setLocale('fr');
        $translation = new BlogPost()->setLocale('en')->setTranslationOf($original);

        self::assertSame($original->getTranslationGroup(), $translation->getTranslationGroup());
        self::assertSame('en', $translation->getLocale());
    }

    public function testTheCreationDateIsSetOnceOnPersist(): void
    {
        $post = new BlogPost();

        $post->initializeCreatedAt();
        $createdAt = $post->getCreatedAt();
        $post->initializeCreatedAt();

        self::assertNotNull($createdAt);
        self::assertSame($createdAt, $post->getCreatedAt());
        self::assertNull($post->getUpdatedAt());
    }

    public function testEachUpdateRefreshesTheModificationDate(): void
    {
        $post = new BlogPost();

        $post->refreshUpdatedAt();

        self::assertNotNull($post->getUpdatedAt());
    }

    public function testTheEditableFieldsAreKeptAsGiven(): void
    {
        $image = new File();

        $post = new BlogPost()
            ->setTitle('Héberger un site en France')
            ->setSlug('heberger-un-site-en-france')
            ->setContent('# Titre')
            ->setMetaDescription('Pourquoi et comment.')
            ->setFeaturedImage($image)
            ->setReadingTime(4);

        self::assertSame('Héberger un site en France', $post->getTitle());
        self::assertSame('heberger-un-site-en-france', $post->getSlug());
        self::assertSame('# Titre', $post->getContent());
        self::assertSame('Pourquoi et comment.', $post->getMetaDescription());
        self::assertSame($image, $post->getFeaturedImage());
        self::assertSame(4, $post->getReadingTime());
    }

    public function testAnAttachedFileIsListedOnce(): void
    {
        $file = new File();
        $post = new BlogPost();

        $post->addFile($file)->addFile($file);
        self::assertCount(1, $post->getFiles());

        $post->removeFile($file);
        self::assertCount(0, $post->getFiles());
    }
}
