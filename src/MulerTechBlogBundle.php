<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle;

use MulerTech\BlogBundle\Controller\BlogAdminController;
use MulerTech\BlogBundle\Controller\BlogController;
use MulerTech\BlogBundle\Controller\BlogFileController;
use MulerTech\BlogBundle\Doctrine\BlogPostMetadataListener;
use MulerTech\BlogBundle\Entity\AbstractBlogPost;
use MulerTech\BlogBundle\Form\BlogPostType;
use MulerTech\BlogBundle\Repository\BlogPostRepository;
use MulerTech\BlogBundle\Service\BlogMediaManager;
use MulerTech\BlogBundle\Service\BlogPostPublisher;
use MulerTech\BlogBundle\Service\SlugGenerator;
use MulerTech\BlogBundle\Service\ViewCounter;
use MulerTech\BlogBundle\Twig\BlogExtension;
use MulerTech\FileBundle\Service\FileManagerInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Form\FormTypeInterface;
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
                ->scalarNode('public_layout')
                    ->defaultValue('base.html.twig')
                    ->cannotBeEmpty()
                    ->info('Template the public pages extend. They fill its title, seo_meta and body blocks')
                ->end()
                ->scalarNode('admin_layout')
                    ->defaultValue('base.html.twig')
                    ->cannotBeEmpty()
                    ->info('Template the admin pages extend. They fill its title and body blocks, and leave flash messages to it')
                ->end()
                ->scalarNode('admin_role')
                    ->defaultValue('ROLE_ADMIN')
                    ->cannotBeEmpty()
                    ->info('Role every admin action checks, whatever the firewall says')
                ->end()
                ->scalarNode('post_form_type')
                    ->defaultValue(BlogPostType::class)
                    ->info('Form of the admin. A site adding fields gives a type whose getParent() returns '.BlogPostType::class)
                    ->validate()
                        ->ifTrue(static fn (mixed $class): bool => !\is_string($class) || !is_a($class, FormTypeInterface::class, true))
                        ->thenInvalid('mulertech_blog.post_form_type must name a form type, got %s.')
                    ->end()
                ->end()
                ->arrayNode('locales')
                    ->info('Languages the articles are written in. With more than one, the admin asks for the language of each article')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                    ->requiresAtLeastOneElement()
                    ->defaultValue(['%kernel.default_locale%'])
                ->end()
            ->end();
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // The admin script is served by the site's AssetMapper, as `@mulertech/blog-bundle/admin.js`.
        $builder->prependExtensionConfig('framework', [
            'asset_mapper' => ['paths' => [$this->getPath().'/assets' => '@mulertech/blog-bundle']],
        ]);
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

        $services->set('mulertech_blog.controller.blog', BlogController::class)
            ->args([
                '$repository' => new Reference('mulertech_blog.repository'),
                '$viewCounter' => new Reference('mulertech_blog.view_counter'),
                '$twig' => new Reference('twig'),
                '$publicLayout' => $config['public_layout'],
            ])
            ->tag('controller.service_arguments')
            ->public();

        $services->set('mulertech_blog.media_manager', BlogMediaManager::class)
            ->args([
                '$fileManager' => new Reference(FileManagerInterface::class),
                '$tokenStorage' => new Reference('security.token_storage'),
                '$entityManager' => new Reference('doctrine.orm.entity_manager'),
            ]);
        $services->alias(BlogMediaManager::class, 'mulertech_blog.media_manager');

        $services->set('mulertech_blog.twig_extension', BlogExtension::class)
            ->args(['$urlGenerator' => new Reference('router')])
            ->tag('twig.extension');

        $services->set('mulertech_blog.controller.admin', BlogAdminController::class)
            ->args([
                '$repository' => new Reference('mulertech_blog.repository'),
                '$entityManager' => new Reference('doctrine.orm.entity_manager'),
                '$mediaManager' => new Reference('mulertech_blog.media_manager'),
                '$publisher' => new Reference('mulertech_blog.publisher'),
                '$slugGenerator' => new Reference('mulertech_blog.slug_generator'),
                '$fileManager' => new Reference(FileManagerInterface::class),
                '$formFactory' => new Reference('form.factory'),
                '$twig' => new Reference('twig'),
                '$urlGenerator' => new Reference('router'),
                '$authorizationChecker' => new Reference('security.authorization_checker'),
                '$csrfTokenManager' => new Reference('security.csrf.token_manager'),
                '$translator' => new Reference('translator'),
                '$postClass' => $config['post_class'],
                '$postFormType' => $config['post_form_type'],
                '$locales' => $config['locales'],
                '$adminLayout' => $config['admin_layout'],
                '$adminRole' => $config['admin_role'],
            ])
            ->tag('controller.service_arguments')
            ->public();

        $services->set('mulertech_blog.controller.file', BlogFileController::class)
            ->args([
                '$repository' => new Reference('mulertech_blog.repository'),
                '$fileManager' => new Reference(FileManagerInterface::class),
            ])
            ->tag('controller.service_arguments')
            ->public();
    }
}
