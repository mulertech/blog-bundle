<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Controller;

use MulerTech\BlogBundle\Repository\BlogPostRepository;
use MulerTech\BlogBundle\Tests\DatabaseTestCase;
use MulerTech\BlogBundle\Tests\Fixtures\Entity\BlogPost;
use MulerTech\BlogBundle\Twig\BlogExtension;
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

        $this->urlGenerator = new UrlGenerator($routes, new RequestContext(host: 'example.com', scheme: 'https', parameters: ['_locale' => 'fr']));
        $this->twig = new Environment($this->templates, ['strict_variables' => true]);
        $this->twig->addExtension(new RoutingExtension($this->urlGenerator));
        $this->twig->addExtension(new TranslationExtension($this->translator));
        $this->twig->addExtension(new BlogExtension($this->urlGenerator));
        $this->twig->addExtension(new MarkdownExtension());
        $this->twig->addRuntimeLoader(new FactoryRuntimeLoader([MarkdownRuntime::class => static fn (): MarkdownRuntime => new MarkdownRuntime(new LeagueMarkdown())]));

        $this->requestStack = new RequestStack();
    }

    /**
     * @param array<string, string> $query
     */
    protected function request(array $query = [], string $locale = 'fr'): Request
    {
        $request = new Request($query);
        $request->setLocale($locale);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $this->requestStack->push($request);

        return $request;
    }

    protected function repository(): BlogPostRepository
    {
        return new BlogPostRepository($this->entityManager, BlogPost::class);
    }
}
