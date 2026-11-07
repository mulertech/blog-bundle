<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests;

use MulerTech\BlogBundle\Doctrine\BlogPostMetadataListener;
use MulerTech\BlogBundle\MulerTechBlogBundle;
use MulerTech\BlogBundle\Repository\BlogPostRepository;
use MulerTech\BlogBundle\Service\BlogPostPublisher;
use MulerTech\BlogBundle\Service\SlugGenerator;
use MulerTech\BlogBundle\Service\ViewCounter;
use MulerTech\BlogBundle\Tests\Fixtures\Entity\BlogPost;
use MulerTech\BlogBundle\Tests\Fixtures\Entity\File;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class MulerTechBlogBundleTest extends TestCase
{
    public function testTheConfigurationIsReadUnderMulertechBlog(): void
    {
        self::assertSame('mulertech_blog', new MulerTechBlogBundle()->getContainerExtension()?->getAlias());
    }

    public function testTheMetadataListenerCompletesEveryLoadedClass(): void
    {
        $definition = $this->load(['post_class' => BlogPost::class])->getDefinition('mulertech_blog.doctrine.blog_post_metadata_listener');

        self::assertSame(BlogPostMetadataListener::class, $definition->getClass());
        self::assertSame([['event' => 'loadClassMetadata']], $definition->getTag('doctrine.event_listener'));
    }

    public function testTheQueriesRunOnTheSiteEntity(): void
    {
        $container = $this->load(['post_class' => BlogPost::class]);

        self::assertSame(BlogPost::class, $container->getDefinition('mulertech_blog.repository')->getArgument('$postClass'));
    }

    public function testTheServicesAreReachableByTheirClass(): void
    {
        $container = $this->load(['post_class' => BlogPost::class]);

        foreach ([BlogPostRepository::class, SlugGenerator::class, BlogPostPublisher::class, ViewCounter::class] as $class) {
            self::assertTrue($container->hasAlias($class), $class);
            self::assertSame($class, $container->findDefinition($class)->getClass());
        }
    }

    /**
     * Without it, no query knows which table to read: the container refuses to build
     * rather than leaving the first page to fail.
     */
    public function testThePostClassIsRequired(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"post_class" under "mulertech_blog" must be configured');

        $this->load([]);
    }

    public function testThePostClassMustExtendTheBundleEntity(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('mulertech_blog.post_class must name the site\'s entity extending MulerTech\BlogBundle\Entity\AbstractBlogPost');

        $this->load(['post_class' => File::class]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        new MulerTechBlogBundle()->getContainerExtension()?->load([$config], $container);

        return $container;
    }
}
