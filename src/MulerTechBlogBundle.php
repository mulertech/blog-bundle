<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle;

use MulerTech\BlogBundle\Doctrine\BlogPostMetadataListener;
use MulerTech\BlogBundle\Entity\AbstractBlogPost;
use MulerTech\BlogBundle\Repository\BlogPostRepository;
use MulerTech\BlogBundle\Service\BlogPostPublisher;
use MulerTech\BlogBundle\Service\SlugGenerator;
use MulerTech\BlogBundle\Service\ViewCounter;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class MulerTechBlogBundle extends AbstractBundle
{
    protected string $extensionAlias = 'mulertech_blog';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('post_class')
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->info('The site\'s blog post entity, extending '.AbstractBlogPost::class)
                    ->validate()
                        ->ifTrue(static fn (mixed $class): bool => !\is_string($class) || !is_subclass_of($class, AbstractBlogPost::class))
                        ->thenInvalid('mulertech_blog.post_class must name the site\'s entity extending '.AbstractBlogPost::class.', got %s.')
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        $services->set('mulertech_blog.doctrine.blog_post_metadata_listener', BlogPostMetadataListener::class)
            ->tag('doctrine.event_listener', ['event' => 'loadClassMetadata']);

        $services->set('mulertech_blog.repository', BlogPostRepository::class)
            ->args([
                '$entityManager' => new Reference('doctrine.orm.entity_manager'),
                '$postClass' => $config['post_class'],
            ]);
        $services->alias(BlogPostRepository::class, 'mulertech_blog.repository');

        $services->set('mulertech_blog.slug_generator', SlugGenerator::class)
            ->args(['$repository' => new Reference('mulertech_blog.repository')]);
        $services->alias(SlugGenerator::class, 'mulertech_blog.slug_generator');

        $services->set('mulertech_blog.publisher', BlogPostPublisher::class)
            ->args([
                '$entityManager' => new Reference('doctrine.orm.entity_manager'),
                '$clock' => new Reference('clock'),
            ]);
        $services->alias(BlogPostPublisher::class, 'mulertech_blog.publisher');

        $services->set('mulertech_blog.view_counter', ViewCounter::class)
            ->args([
                '$entityManager' => new Reference('doctrine.orm.entity_manager'),
                '$requestStack' => new Reference('request_stack'),
            ]);
        $services->alias(ViewCounter::class, 'mulertech_blog.view_counter');
    }
}
