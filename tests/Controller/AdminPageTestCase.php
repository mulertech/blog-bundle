<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Controller;

use Symfony\Bridge\Twig\Extension\CsrfExtension;
use Symfony\Bridge\Twig\Extension\CsrfRuntime;
use Symfony\Bridge\Twig\Extension\FormExtension;
use Symfony\Bridge\Twig\Form\TwigRendererEngine;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormRenderer;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\Validation;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

/**
 * Renders the admin pages the way a site does: forms, CSRF tokens and flash messages,
 * with the French labels.
 */
abstract class AdminPageTestCase extends PublicPageTestCase
{
    protected const string TOKEN = 'valid-token';

    protected bool $granted = true;
    private ?Session $session = null;

    protected function setUp(): void
    {
        parent::setUp();

        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturnCallback(static fn (string $id): CsrfToken => new CsrfToken($id, self::TOKEN));
        $this->templates->addPath(\dirname(__DIR__, 2).'/vendor/symfony/twig-bridge/Resources/views/Form');
        $engine = new TwigRendererEngine(['form_div_layout.html.twig'], $this->twig);
        $this->twig->addExtension(new FormExtension());
        $this->twig->addExtension(new CsrfExtension());
        $this->twig->addRuntimeLoader(new FactoryRuntimeLoader([
            FormRenderer::class => static fn (): FormRenderer => new FormRenderer($engine),
            CsrfRuntime::class => static fn (): CsrfRuntime => new CsrfRuntime($csrf),
        ]));
    }

    protected function formFactory(): FormFactoryInterface
    {
        $validator = Validation::createValidatorBuilder()
            ->setTranslator($this->translator)
            ->setTranslationDomain('validators')
            ->getValidator();

        return Forms::createFormFactoryBuilder()
            ->addExtension(new HttpFoundationExtension())
            ->addExtension(new ValidatorExtension($validator))
            ->getFormFactory();
    }

    protected function authorizationChecker(): AuthorizationCheckerInterface
    {
        $authorizationChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturn($this->granted);

        return $authorizationChecker;
    }

    protected function csrfTokenManager(): CsrfTokenManagerInterface
    {
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(static fn (CsrfToken $token): bool => self::TOKEN === $token->getValue());

        return $csrf;
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $files
     */
    protected function post_(array $fields, array $files = []): Request
    {
        $request = new Request(request: $fields, files: $files, server: ['REQUEST_METHOD' => 'POST']);
        $request->setLocale('fr');
        $request->setSession($this->session ??= new Session(new MockArraySessionStorage()));
        $this->requestStack->push($request);

        return $request;
    }

    /**
     * @return list<string>
     */
    protected function flashes(string $type): array
    {
        /** @var list<string> */
        return $this->session?->getFlashBag()->peek($type) ?? [];
    }

    protected function crawl(Response $response): Crawler
    {
        return new Crawler((string) $response->getContent());
    }

    protected function redirect(Response $response): string
    {
        self::assertInstanceOf(RedirectResponse::class, $response);

        return $response->getTargetUrl();
    }
}
