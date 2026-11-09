<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests;

use Symfony\Component\Clock\MockClock;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\Tools\ResolveTargetEntityListener;
use Doctrine\ORM\Tools\SchemaTool;
use MulerTech\BlogBundle\Doctrine\BlogPostMetadataListener;
use MulerTech\BlogBundle\Entity\AbstractBlogPost;
use MulerTech\BlogBundle\Tests\Fixtures\Entity\BlogPost;
use MulerTech\BlogBundle\Tests\Fixtures\Entity\BlogCategory;
use MulerTech\BlogBundle\Tests\Fixtures\Entity\BlogTag;
use MulerTech\BlogBundle\Tests\Fixtures\Entity\File;
use MulerTech\FileBundle\Model\FileInterface;
use PHPUnit\Framework\TestCase;

/**
 * Runs the queries of the bundle against an in-memory SQLite database built from the
 * mapping a site gets, so DQL that Doctrine accepts but the database refuses fails here.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected EntityManager $entityManager;

    /**
     * After every date the tests publish at: an article dated later is scheduled.
     */
    protected MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-07-01 12:00');

        $configuration = new Configuration();
        $configuration->setMetadataDriverImpl(new AttributeDriver([
            \dirname(new \ReflectionClass(AbstractBlogPost::class)->getFileName() ?: ''),
            __DIR__.'/Fixtures/Entity',
        ]));
        $configuration->setNamingStrategy(new UnderscoreNamingStrategy(\CASE_LOWER, true));
        $configuration->enableNativeLazyObjects(true);

        $resolveTargetEntities = new ResolveTargetEntityListener();
        $resolveTargetEntities->addResolveTargetEntity(FileInterface::class, File::class, []);

        $eventManager = new EventManager();
        $eventManager->addEventSubscriber($resolveTargetEntities);
        $eventManager->addEventListener(Events::loadClassMetadata, $this->metadataListener());

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
        $this->entityManager = new EntityManager($connection, $configuration, $eventManager);

        new SchemaTool($this->entityManager)->createSchema([
            $this->entityManager->getClassMetadata(BlogPost::class),
            $this->entityManager->getClassMetadata(File::class),
            $this->entityManager->getClassMetadata(BlogCategory::class),
            $this->entityManager->getClassMetadata(BlogTag::class),
        ]);
    }

    protected function metadataListener(): BlogPostMetadataListener
    {
        return new BlogPostMetadataListener(BlogCategory::class, BlogTag::class);
    }

    protected function post(string $title, string $locale = 'fr', ?string $publishedAt = '2026-01-01 10:00', string $content = 'Contenu.', ?string $slug = null): BlogPost
    {
        $post = new BlogPost()
            ->setTitle($title)
            ->setSlug($slug ?? strtolower(str_replace(' ', '-', $title)))
            ->setContent($content)
            ->setLocale($locale);

        if (null !== $publishedAt) {
            $post->publish(new \DateTimeImmutable($publishedAt));
        }

        $this->entityManager->persist($post);
        $this->entityManager->flush();

        return $post;
    }

    protected function category(string $name, string $locale = 'fr', ?string $description = null): BlogCategory
    {
        $category = new BlogCategory()->setName($name)->setSlug(strtolower(str_replace(' ', '-', $name)))->setLocale($locale)->setDescription($description);
        $this->entityManager->persist($category);
        $this->entityManager->flush();

        return $category;
    }

    protected function tag(string $name, string $locale = 'fr'): BlogTag
    {
        $tag = new BlogTag()->setName($name)->setSlug(strtolower(str_replace(' ', '-', $name)))->setLocale($locale);
        $this->entityManager->persist($tag);
        $this->entityManager->flush();

        return $tag;
    }

    protected function setViewCount(BlogPost $post, int $viewCount): void
    {
        $this->entityManager->getConnection()->executeStatement('UPDATE blog_posts SET view_count = ? WHERE id = ?', [$viewCount, $post->getId()]);
    }
}
