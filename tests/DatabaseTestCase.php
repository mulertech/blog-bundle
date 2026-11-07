<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests;

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

    protected function setUp(): void
    {
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
        $eventManager->addEventListener(Events::loadClassMetadata, new BlogPostMetadataListener());

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
        $this->entityManager = new EntityManager($connection, $configuration, $eventManager);

        new SchemaTool($this->entityManager)->createSchema([
            $this->entityManager->getClassMetadata(BlogPost::class),
            $this->entityManager->getClassMetadata(File::class),
        ]);
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

    protected function setViewCount(BlogPost $post, int $viewCount): void
    {
        $this->entityManager->getConnection()->executeStatement('UPDATE blog_posts SET view_count = ? WHERE id = ?', [$viewCount, $post->getId()]);
    }
}
