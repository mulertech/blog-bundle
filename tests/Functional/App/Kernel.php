<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Functional\App;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use MulerTech\BlogBundle\MulerTechBlogBundle;
use MulerTech\BlogBundle\Tests\Functional\App\Entity\Article;
use MulerTech\BlogBundle\Tests\Functional\App\Entity\Document;
use MulerTech\BlogBundle\Tests\Functional\App\Security\EditorProvider;
use MulerTech\BlogBundle\Tests\Functional\App\Seo\CompanyInfoProvider;
use MulerTech\CspBundle\MulerTechCspBundle;
use MulerTech\FileBundle\Model\FileInterface;
use MulerTech\FileBundle\MulerTechFileBundle;
use MulerTech\SeoBundle\Model\SeoCompanyInfoProviderInterface;
use MulerTech\SeoBundle\MulerTechSeoBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

/**
 * A site with nothing but the blog, set up the way the README tells a site to.
 */
final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new TwigExtraBundle();
        yield new SecurityBundle();
        yield new DoctrineBundle();
        yield new MulerTechCspBundle();
        yield new MulerTechFileBundle();
        yield new MulerTechSeoBundle();
        yield new MulerTechBlogBundle();
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/mulertech_blog_bundle/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/mulertech_blog_bundle/log';
    }

    public static function storageDirectory(): string
    {
        return sys_get_temp_dir().'/mulertech_blog_bundle/storage';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'default_locale' => 'fr',
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'csrf_protection' => true,
            'form' => true,
            'translator' => ['default_path' => '%kernel.project_dir%/translations'],
            'router' => ['utf8' => true],
            'asset_mapper' => ['paths' => []],
        ]);
        $container->extension('twig', ['default_path' => '%kernel.project_dir%/templates']);
        $container->extension('doctrine', [
            'dbal' => ['driver' => 'pdo_sqlite', 'path' => sys_get_temp_dir().'/mulertech_blog_bundle/database.sqlite'],
            'orm' => [
                'mappings' => [
                    'App' => ['type' => 'attribute', 'dir' => '%kernel.project_dir%/Entity', 'prefix' => 'MulerTech\BlogBundle\Tests\Functional\App\Entity', 'is_bundle' => false],
                ],
                'resolve_target_entities' => [FileInterface::class => Document::class],
            ],
        ]);
        $container->extension('security', [
            'providers' => ['editors' => ['id' => EditorProvider::class]],
            'firewalls' => ['main' => ['lazy' => true, 'provider' => 'editors']],
            'access_control' => [['path' => '^/admin', 'roles' => 'ROLE_ADMIN']],
        ]);
        $container->extension('mulertech_file', [
            'storage_directory' => self::storageDirectory(),
            'file_class' => Document::class,
        ]);
        $container->extension('mulertech_seo', [
            'default_image' => '/images/og-default.jpg',
        ]);
        $container->extension('mulertech_blog', [
            'post_class' => Article::class,
        ]);

        $container->services()
            ->set(EditorProvider::class)
            ->set(SeoCompanyInfoProviderInterface::class, CompanyInfoProvider::class);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@MulerTechSeoBundle/config/routes.yaml');
        $routes->import('@MulerTechBlogBundle/config/routes.yaml')->prefix('/blog', false);
        $routes->import('@MulerTechBlogBundle/config/admin_routes.yaml')->prefix('/admin/blog', false);
    }
}
