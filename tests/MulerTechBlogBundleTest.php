<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests;

use MulerTech\BlogBundle\Doctrine\BlogPostMetadataListener;
use MulerTech\BlogBundle\MulerTechBlogBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class MulerTechBlogBundleTest extends TestCase
{
    public function testTheConfigurationIsReadUnderMulertechBlog(): void
    {
        self::assertSame('mulertech_blog', new MulerTechBlogBundle()->getContainerExtension()?->getAlias());
    }

    public function testTheMetadataListenerCompletesEveryLoadedClass(): void
    {
        $container = new ContainerBuilder();
        new MulerTechBlogBundle()->getContainerExtension()?->load([], $container);

        $definition = $container->getDefinition('mulertech_blog.doctrine.blog_post_metadata_listener');

        self::assertSame(BlogPostMetadataListener::class, $definition->getClass());
        self::assertSame([['event' => 'loadClassMetadata']], $definition->getTag('doctrine.event_listener'));
    }
}
