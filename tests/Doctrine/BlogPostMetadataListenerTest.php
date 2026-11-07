<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Doctrine;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\DefaultNamingStrategy;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Doctrine\ORM\Mapping\NamingStrategy;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\Tools\ResolveTargetEntityListener;
use MulerTech\BlogBundle\Doctrine\BlogPostMetadataListener;
use MulerTech\BlogBundle\Entity\AbstractBlogPost;
use MulerTech\BlogBundle\Tests\Fixtures\Entity\BlogPost;
use MulerTech\BlogBundle\Tests\Fixtures\Entity\File;
use MulerTech\FileBundle\Model\FileInterface;
use PHPUnit\Framework\TestCase;

/**
 * The mapping a site gets for its blog post entity, read from the metadata Doctrine builds
 * the schema from. No connection is opened.
 */
final class BlogPostMetadataListenerTest extends TestCase
{
    /**
     * The columns keep the names the mulertech table already has, so a site moving onto
     * the bundle adds columns without renaming any, whatever its naming strategy.
     */
    public function testTheColumnsKeepTheirNamesWhateverTheNamingStrategy(): void
    {
        $metadata = $this->entityManager(new DefaultNamingStrategy())->getClassMetadata(BlogPost::class);

        self::assertSame([
            'id' => 'id',
            'title' => 'title',
            'slug' => 'slug',
            'content' => 'content',
            'metaDescription' => 'meta_description',
            'isPublished' => 'is_published',
            'publishedAt' => 'published_at',
            'readingTime' => 'reading_time',
            'viewCount' => 'view_count',
            'createdAt' => 'created_at',
            'updatedAt' => 'updated_at',
            'locale' => 'locale',
            'translationGroup' => 'translation_group',
        ], array_combine($metadata->getFieldNames(), array_map($metadata->getColumnName(...), $metadata->getFieldNames())));
        self::assertSame('featured_image_id', $metadata->getSingleAssociationJoinColumnName('featuredImage'));
    }

    public function testTheSlugIsUniquePerLanguage(): void
    {
        $metadata = $this->entityManager()->getClassMetadata(BlogPost::class);

        self::assertContains(['columns' => ['slug', 'locale']], $metadata->table['uniqueConstraints'] ?? []);
    }

    public function testTheVersionsOfAnArticleAreFoundThroughAnIndex(): void
    {
        $metadata = $this->entityManager()->getClassMetadata(BlogPost::class);

        self::assertContains(['columns' => ['translation_group']], $metadata->table['indexes'] ?? []);
    }

    /**
     * Doctrine names the join table after the entity, not after the mapped superclass
     * declaring the association: each site gets its own, mulertech its `blog_post_file`.
     */
    public function testTheFeaturedImageAndAttachedFilesPointAtTheSiteFileEntity(): void
    {
        $metadata = $this->entityManager()->getClassMetadata(BlogPost::class);
        $files = $metadata->getAssociationMapping('files');

        self::assertSame(File::class, $metadata->getAssociationTargetClass('featuredImage'));
        self::assertSame(File::class, $files->targetEntity);
        self::assertInstanceOf(ManyToManyOwningSideMapping::class, $files);
        self::assertSame('blog_post_file', $files->joinTable->name);
        self::assertSame('blog_post_id', $files->joinTable->joinColumns[0]->name);
        self::assertSame('file_id', $files->joinTable->inverseJoinColumns[0]->name);
    }

    /**
     * Symfony does not order the two listeners of `loadClassMetadata`: the files must reach
     * the site's entity whichever runs first.
     */
    public function testTheFilesReachTheSiteEntityWhenTheResolverRunsLast(): void
    {
        $metadata = $this->entityManager(resolverFirst: false)->getClassMetadata(BlogPost::class);

        self::assertSame(File::class, $metadata->getAssociationTargetClass('files'));
        self::assertSame(File::class, $metadata->getAssociationTargetClass('featuredImage'));
    }

    public function testOtherEntitiesAreLeftAlone(): void
    {
        $metadata = $this->entityManager()->getClassMetadata(File::class);

        self::assertArrayNotHasKey('uniqueConstraints', $metadata->table);
        self::assertArrayNotHasKey('indexes', $metadata->table);
    }

    private function entityManager(NamingStrategy $namingStrategy = new UnderscoreNamingStrategy(\CASE_LOWER, true), bool $resolverFirst = true): EntityManager
    {
        $configuration = new Configuration();
        $configuration->setMetadataDriverImpl(new AttributeDriver([
            \dirname(new \ReflectionClass(AbstractBlogPost::class)->getFileName() ?: ''),
            \dirname(__DIR__).'/Fixtures/Entity',
        ]));
        $configuration->setNamingStrategy($namingStrategy);
        $configuration->enableNativeLazyObjects(true);

        $resolveTargetEntities = new ResolveTargetEntityListener();
        $resolveTargetEntities->addResolveTargetEntity(FileInterface::class, File::class, []);

        $eventManager = new EventManager();

        if ($resolverFirst) {
            $eventManager->addEventSubscriber($resolveTargetEntities);
            $eventManager->addEventListener(Events::loadClassMetadata, new BlogPostMetadataListener());
        } else {
            $eventManager->addEventListener(Events::loadClassMetadata, new BlogPostMetadataListener());
            $eventManager->addEventSubscriber($resolveTargetEntities);
        }

        $connection = DriverManager::getConnection(['driver' => 'pdo_pgsql', 'serverVersion' => '16'], $configuration, $eventManager);

        return new EntityManager($connection, $configuration, $eventManager);
    }
}
