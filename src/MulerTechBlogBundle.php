<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle;

use MulerTech\BlogBundle\Doctrine\BlogPostMetadataListener;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class MulerTechBlogBundle extends AbstractBundle
{
    protected string $extensionAlias = 'mulertech_blog';

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->services()
            ->set('mulertech_blog.doctrine.blog_post_metadata_listener', BlogPostMetadataListener::class)
            ->tag('doctrine.event_listener', ['event' => 'loadClassMetadata']);
    }
}
