<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests;

use MulerTech\BlogBundle\Controller\BlogController;
use MulerTech\BlogBundle\Controller\BlogFileController;
use MulerTech\BlogBundle\Doctrine\BlogPostMetadataListener;
use MulerTech\BlogBundle\Form\BlogPostType;
use MulerTech\BlogBundle\MulerTechBlogBundle;
use MulerTech\BlogBundle\Repository\BlogPostRepository;
use MulerTech\BlogBundle\Service\BlogPostPublisher;
use MulerTech\BlogBundle\Service\SlugGenerator;
use MulerTech\BlogBundle\Service\ViewCounter;
use MulerTech\BlogBundle\Tests\Fixtures\Entity\BlogPost;
use MulerTech\BlogBundle\Tests\Fixtures\Entity\File;
use MulerTech\BlogBundle\Tests\Fixtures\ExtendedBlogPostType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use MulerTech\CspBundle\CspNonceGenerator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;

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

    public function testThePublicPagesExtendTheSiteBaseLayoutUnlessToldOtherwise(): void
    {
        self::assertSame('base.html.twig', $this->load(['post_class' => BlogPost::class])->getDefinition('mulertech_blog.controller.blog')->getArgument('$publicLayout'));
        self::assertSame('layouts/public.html.twig', $this->load(['post_class' => BlogPost::class, 'public_layout' => 'layouts/public.html.twig'])->getDefinition('mulertech_blog.controller.blog')->getArgument('$publicLayout'));
    }

    public function testTheControllersAreReachableByTheRoutes(): void
    {
        $container = $this->load(['post_class' => BlogPost::class]);

        foreach (['mulertech_blog.controller.blog' => BlogController::class, 'mulertech_blog.controller.file' => BlogFileController::class] as $id => $class) {
            $definition = $container->getDefinition($id);
            self::assertSame($class, $definition->getClass());
            self::assertTrue($definition->isPublic(), $id);
            self::assertTrue($definition->hasTag('controller.service_arguments'), $id);
        }
    }

    public function testTheAdminScriptIsServedByTheSiteAssetMapper(): void
    {
        $container = new ContainerBuilder();
        $extension = new MulerTechBlogBundle()->getContainerExtension();
        self::assertInstanceOf(PrependExtensionInterface::class, $extension);

        $extension->prepend($container);

        self::assertSame(
            [['asset_mapper' => ['paths' => [\dirname(__DIR__).'/assets' => '@mulertech/blog-bundle']]]],
            $container->getExtensionConfig('framework'),
        );
        self::assertFileExists(\dirname(__DIR__).'/assets/admin.js');
    }

    /**
     * Unknown to Doctrine, the mapped superclass would be taken for a plain parent class,
     * and its lifecycle callbacks lost.
     */
    public function testTheEntityOfTheBundleIsKnownToDoctrine(): void
    {
        $container = new ContainerBuilder();
        $extension = new MulerTechBlogBundle()->getContainerExtension();
        self::assertInstanceOf(PrependExtensionInterface::class, $extension);

        $extension->prepend($container);

        self::assertSame([['orm' => ['mappings' => ['MulerTechBlogBundle' => [
            'type' => 'attribute',
            'dir' => \dirname(__DIR__).'/src/Entity',
            'prefix' => 'MulerTech\\BlogBundle\\Entity',
            'is_bundle' => false,
        ]]]]], $container->getExtensionConfig('doctrine'));
    }

    public function testTheAdminControllerReceivesTheAdminSettings(): void
    {
        $definition = $this->load([
            'post_class' => BlogPost::class,
            'admin_layout' => 'admin/base.html.twig',
            'admin_role' => 'ROLE_EDITOR',
            'post_form_type' => ExtendedBlogPostType::class,
            'locales' => ['fr', 'en'],
        ])->getDefinition('mulertech_blog.controller.admin');

        self::assertSame('admin/base.html.twig', $definition->getArgument('$adminLayout'));
        self::assertSame('ROLE_EDITOR', $definition->getArgument('$adminRole'));
        self::assertSame(ExtendedBlogPostType::class, $definition->getArgument('$postFormType'));
        self::assertSame(['fr', 'en'], $definition->getArgument('$locales'));
        self::assertTrue($definition->isPublic());
    }

    /**
     * A single-language site declares nothing: its default locale is the language.
     */
    public function testTheLanguageDefaultsToTheSiteDefaultLocale(): void
    {
        $definition = $this->load(['post_class' => BlogPost::class])->getDefinition('mulertech_blog.controller.admin');

        self::assertSame(['%kernel.default_locale%'], $definition->getArgument('$locales'));
        self::assertSame('ROLE_ADMIN', $definition->getArgument('$adminRole'));
        self::assertSame(BlogPostType::class, $definition->getArgument('$postFormType'));
    }

    public function testThePostFormTypeMustBeAFormType(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('mulertech_blog.post_form_type must name a form type');

        $this->load(['post_class' => BlogPost::class, 'post_form_type' => BlogPost::class]);
    }

    /**
     * seo-bundle collects its providers by tag: one left untagged would leave the blog out
     * of the sitemap, `llms.txt` or the social cards, without a word.
     */
    public function testTheSeoProvidersAreHandedToSeoBundle(): void
    {
        $container = $this->load(['post_class' => BlogPost::class]);

        foreach ([
            'mulertech_blog.seo.social_card_provider' => 'mulertech_seo.social_card_source_provider',
            'mulertech_blog.seo.sitemap_provider' => 'mulertech_seo.sitemap_url_provider',
            'mulertech_blog.seo.llms_section_provider' => 'mulertech_seo.llms_section_provider',
        ] as $id => $tag) {
            self::assertTrue($container->getDefinition($id)->hasTag($tag), $id);
        }
    }

    /**
     * Without csp-bundle the reference resolves to null rather than failing the container.
     */
    public function testTheCspNonceIsOptional(): void
    {
        $reference = $this->load(['post_class' => BlogPost::class])->getDefinition('mulertech_blog.twig_extension')->getArgument('$nonceGenerator');

        self::assertInstanceOf(Reference::class, $reference);
        self::assertSame(CspNonceGenerator::class, (string) $reference);
        self::assertSame(ContainerInterface::IGNORE_ON_INVALID_REFERENCE, $reference->getInvalidBehavior());
    }

    public function testTheAddressesFollowTheDeclaredLanguages(): void
    {
        self::assertSame(['fr', 'en'], $this->load(['post_class' => BlogPost::class, 'locales' => ['fr', 'en']])->getDefinition('mulertech_blog.post_url_generator')->getArgument('$locales'));
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
