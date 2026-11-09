<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Controller;

use MulerTech\BlogBundle\Repository\BlogPostRepository;
use MulerTech\BlogBundle\Repository\BlogTermRepository;
use MulerTech\BlogBundle\Tests\Fixtures\Entity\BlogCategory;
use MulerTech\BlogBundle\Tests\Fixtures\Entity\BlogTag;
use MulerTech\BlogBundle\Service\PostUrlGenerator;
use MulerTech\BlogBundle\Tests\DatabaseTestCase;
use MulerTech\BlogBundle\Tests\Fixtures\Entity\BlogPost;
use MulerTech\BlogBundle\Twig\BlogExtension;
use MulerTech\SeoBundle\Model\SeoCompanyInfoProviderInterface;
use MulerTech\SeoBundle\Service\MetaTagService;
use MulerTech\SeoBundle\Service\SchemaOrgService;
use MulerTech\SeoBundle\Twig\SeoExtension;
use Symfony\Bridge\Twig\Extension\RoutingExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Loader\YamlFileLoader;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Translation\Loader\YamlFileLoader as TranslationYamlLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Symfony\Bridge\Twig\Extension\AssetExtension;
use Symfony\Component\Asset\Package;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Asset\VersionStrategy\EmptyVersionStrategy;
use Twig\Extra\Markdown\LeagueMarkdown;
use Twig\Extra\Markdown\MarkdownExtension;
use Twig\Extra\Markdown\MarkdownRuntime;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

/**
 * Renders the bundle's templates the way a site does: under a route prefix, through a
 * layout of its own, with the French labels.
 */
abstract class PublicPageTestCase extends DatabaseTestCase
{
    protected RequestStack $requestStack;
    protected UrlGenerator $urlGenerator;
    protected MetaTagService $metaTagService;
    protected Environment $twig;
    protected Translator $translator;
    protected FilesystemLoader $templates;

    protected function setUp(): void
    {
        parent::setUp();

        $root = \dirname(__DIR__, 2);
        $routes = new YamlFileLoader(new FileLocator(\dirname(__DIR__).'/Fixtures/config'))->load('routes.yaml');

        $this->translator = new Translator('fr');
        $this->translator->addLoader('yaml', new TranslationYamlLoader());
        $this->translator->addResource('yaml', $root.'/translations/MulerTechBlogBundle.fr.yaml', 'fr', 'MulerTechBlogBundle');
        $this->translator->addResource('yaml', $root.'/translations/validators.fr.yaml', 'fr', 'validators');

        $this->templates = new FilesystemLoader(\dirname(__DIR__).'/Fixtures/templates');
        $this->templates->addPath($root.'/templates', 'MulerTechBlog');
        $this->templates->addPath($root.'/vendor/mulertech/seo-bundle/templates', 'MulerTechSeo');

        $this->requestStack = new RequestStack();
        $company = $this->createStub(SeoCompanyInfoProviderInterface::class);
        $company->method('getName')->willReturn('Exemple');
        $this->metaTagService = new MetaTagService($this->requestStack, $company, '/images/og-default.jpg');

        $this->urlGenerator = new UrlGenerator($routes, new RequestContext(host: 'example.com', scheme: 'https', parameters: ['_locale' => 'fr']));
        $this->twig = new Environment($this->templates, ['strict_variables' => true]);
        $this->twig->addExtension(new RoutingExtension($this->urlGenerator));
        $this->twig->addExtension(new AssetExtension(new Packages(new Package(new EmptyVersionStrategy()))));
        $this->twig->addExtension(new TranslationExtension($this->translator));
        $this->twig->addExtension(new BlogExtension($this->urlGenerator, $this->postUrls(), new RequestStack(), clock: $this->clock));
        $this->twig->addExtension(new SeoExtension(new SchemaOrgService($company, $this->requestStack), $this->requestStack));
        $this->twig->addExtension(new MarkdownExtension());
        $this->twig->addRuntimeLoader(new FactoryRuntimeLoader([MarkdownRuntime::class => static fn (): MarkdownRuntime => new MarkdownRuntime(new LeagueMarkdown())]));

    }

    /**
     * @param array<string, string> $query
     */
    protected function request(array $query = [], string $locale = 'fr', string $path = '/fr/blog'): Request
    {
        $request = Request::create('https://example.com'.$path, 'GET', $query);
        $request->setLocale($locale);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $this->requestStack->push($request);

        return $request;
    }

    /**
     * @param list<string> $locales
     */
    protected function postUrls(array $locales = ['fr', 'en']): PostUrlGenerator
    {
        return new PostUrlGenerator($this->urlGenerator, $locales);
    }

    protected function categories(bool $enabled = true): BlogTermRepository
    {
        return new BlogTermRepository($this->entityManager, $enabled ? BlogCategory::class : null, 'mulertech_blog.category_class');
    }

    protected function tags(bool $enabled = true): BlogTermRepository
    {
        return new BlogTermRepository($this->entityManager, $enabled ? BlogTag::class : null, 'mulertech_blog.tag_class');
    }

    protected function repository(): BlogPostRepository
    {
        return new BlogPostRepository($this->entityManager, BlogPost::class, $this->clock);
    }
}
