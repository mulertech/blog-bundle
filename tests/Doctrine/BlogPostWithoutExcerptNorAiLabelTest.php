<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Doctrine;

use MulerTech\BlogBundle\Doctrine\BlogPostMetadataListener;
use MulerTech\BlogBundle\Tests\DatabaseTestCase;
use MulerTech\BlogBundle\Tests\Fixtures\Entity\BlogCategory;
use MulerTech\BlogBundle\Tests\Fixtures\Entity\BlogTag;
use MulerTech\BlogBundle\Tests\Fixtures\Entity\BlogPost;

/**
 * A site turning the excerpt and the AI label off saves and reads its articles on a table
 * that has neither column.
 */
final class BlogPostWithoutExcerptNorAiLabelTest extends DatabaseTestCase
{
    protected function metadataListener(): BlogPostMetadataListener
    {
        return new BlogPostMetadataListener(BlogCategory::class, BlogTag::class, excerpt: false, aiLabel: false);
    }

    public function testAnArticleIsSavedAndReadWithoutEitherColumn(): void
    {
        $columns = array_keys($this->entityManager->getConnection()->createSchemaManager()->listTableColumns('blog_posts'));
        self::assertNotContains('excerpt', $columns);
        self::assertNotContains('ai_generated', $columns);

        $id = $this->post('Bienvenue')->getId();
        $this->entityManager->clear();
        $post = $this->entityManager->find(BlogPost::class, $id);

        self::assertInstanceOf(BlogPost::class, $post);
        self::assertSame('Bienvenue', $post->getTitle());
        self::assertNull($post->getExcerpt());
        self::assertFalse($post->isAiGenerated());

        $post->setTitle('Bienvenue à tous');
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertSame('Bienvenue à tous', $this->entityManager->find(BlogPost::class, $id)?->getTitle());
    }
}
