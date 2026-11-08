<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Controller;

use MulerTech\BlogBundle\Controller\BlogAdminController;
use MulerTech\BlogBundle\Form\BlogPostType;
use MulerTech\BlogBundle\Service\BlogMediaManager;
use MulerTech\BlogBundle\Service\BlogPostPublisher;
use MulerTech\BlogBundle\Service\SlugGenerator;
use MulerTech\BlogBundle\Tests\Fixtures\AdminUser;
use MulerTech\BlogBundle\Tests\Fixtures\Entity\BlogPost;
use MulerTech\BlogBundle\Tests\Fixtures\Entity\File;
use MulerTech\BlogBundle\Tests\Fixtures\ExtendedBlogPostType;
use MulerTech\BlogBundle\Tests\Fixtures\InMemoryFileManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bridge\Twig\Extension\AssetExtension;
use Symfony\Bridge\Twig\Extension\CsrfExtension;
use Symfony\Bridge\Twig\Extension\CsrfRuntime;
use Symfony\Bridge\Twig\Extension\FormExtension;
use Symfony\Bridge\Twig\Form\TwigRendererEngine;
use Symfony\Component\Asset\Package;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Asset\VersionStrategy\EmptyVersionStrategy;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormRenderer;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\Validation;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

final class BlogAdminControllerTest extends PublicPageTestCase
{
    private const string TOKEN = 'valid-token';

    private InMemoryFileManager $fileManager;
    private bool $granted = true;

    protected function setUp(): void
    {
        parent::setUp();

        $directory = sys_get_temp_dir().'/blog_admin_'.uniqid('', true);
        mkdir($directory, 0o775, true);
        $this->fileManager = new InMemoryFileManager($this->entityManager, $directory);

        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturnCallback(static fn (string $id): CsrfToken => new CsrfToken($id, self::TOKEN));
        $this->templates->addPath(\dirname(__DIR__, 2).'/vendor/symfony/twig-bridge/Resources/views/Form');
        $engine = new TwigRendererEngine(['form_div_layout.html.twig'], $this->twig);
        $this->twig->addExtension(new FormExtension());
        $this->twig->addExtension(new CsrfExtension());
        $this->twig->addExtension(new AssetExtension(new Packages(new Package(new EmptyVersionStrategy()))));
        $this->twig->addRuntimeLoader(new FactoryRuntimeLoader([
            FormRenderer::class => static fn (): FormRenderer => new FormRenderer($engine),
            CsrfRuntime::class => static fn (): CsrfRuntime => new CsrfRuntime($csrf),
        ]));
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->fileManager->directory.'/*') ?: []);
        rmdir($this->fileManager->directory);
    }

    public function testTheListHoldsEveryArticleWithItsStatus(): void
    {
        $this->post('Publié', publishedAt: '2026-03-14');
        $this->post('Brouillon', publishedAt: null);
        $this->post('English', 'en');

        $page = $this->crawl($this->controller()->index($this->request()));

        self::assertSame(['English', 'Brouillon', 'Publié'], $page->filter('tbody a')->each(static fn (Crawler $link): string => $link->text()));
        self::assertStringContainsString('Publié le 14/03/2026', $page->filter('tbody tr')->eq(2)->text());
        self::assertStringContainsString('Brouillon', $page->filter('tbody tr')->eq(1)->text());
        self::assertCount(0, $page->filter('th:contains("Langue")'), 'one language, nothing to tell apart');
    }

    public function testTheListShowsTheLanguageOfASiteWithSeveral(): void
    {
        $this->post('English', 'en');

        $page = $this->crawl($this->controller(locales: ['fr', 'en'])->index($this->request()));

        self::assertSame('Langue', $page->filter('th')->eq(1)->text());
        self::assertSame('en', $page->filter('tbody td')->eq(1)->text());
    }

    public function testTheListSearchesTheTitles(): void
    {
        $this->post('Facturation électronique');
        $this->post('Autre');

        $page = $this->crawl($this->controller()->index($this->request(['q' => 'FACTURATION'])));

        self::assertSame(['Facturation électronique'], $page->filter('tbody a')->each(static fn (Crawler $link): string => $link->text()));
    }

    public function testAnEmptyListSaysSo(): void
    {
        self::assertStringContainsString('Aucun article.', $this->crawl($this->controller()->index($this->request()))->text());
    }

    public function testTheListIsPaginated(): void
    {
        foreach (range(1, 21) as $number) {
            $this->post('Article '.$number);
        }

        $page = $this->crawl($this->controller()->index($this->request(['page' => '2'])));

        self::assertCount(1, $page->filter('tbody tr'));
        self::assertSame('/admin/blog', $page->filter('nav a[rel=prev]')->attr('href'));
    }

    #[DataProvider('listPagesThatDoNotExist')]
    public function testAListPageThatDoesNotExistAnswersNotFound(string $page): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->controller()->index($this->request(['page' => $page]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function listPagesThatDoNotExist(): iterable
    {
        yield 'not a number' => ['deux'];
        yield 'beyond the last' => ['2'];
    }

    public function testTheCreationFormHoldsTheSharedFields(): void
    {
        $page = $this->crawl($this->controller()->new($this->request()));

        self::assertSame('Nouvel article', $page->filter('h1')->text());
        foreach (['title', 'slug', 'content', 'metaDescription', 'featuredImage'] as $field) {
            self::assertCount(1, $page->filter(sprintf('[name="blog_post[%s]"]', $field)), $field);
        }
        self::assertCount(0, $page->filter('[name="blog_post[locale]"]'), 'one language, nothing to choose');
        self::assertCount(0, $page->filter('[name="blog_post[removeFeaturedImage]"]'), 'no cover yet');
        self::assertCount(1, $page->filter('textarea[data-mulertech-blog-editor]'));
        self::assertSame('1200', $page->filter('[data-cover-cropper]')->attr('data-cover-width'));
        self::assertSame('675', $page->filter('[data-cover-cropper]')->attr('data-cover-height'));
        self::assertSame('@mulertech/blog-bundle/admin.js', $page->filter('script[type=module]')->attr('src'));
    }

    public function testASiteWithSeveralLanguagesChoosesTheLanguageOfEachArticle(): void
    {
        $controller = $this->controller(locales: ['fr', 'en']);

        $page = $this->crawl($controller->new($this->request()));
        self::assertSame(['fr', 'en'], $page->filter('[name="blog_post[locale]"] option')->each(static fn (Crawler $option): ?string => $option->attr('value')));

        $controller->new($this->submit(['title' => 'Welcome', 'content' => 'Text.', 'locale' => 'en']));

        self::assertSame('en', $this->onlyPost()->getLocale());
    }

    public function testCreatingAnArticleSavesItAsADraftWithASlugFromItsTitle(): void
    {
        $response = $this->controller()->new($this->submit(['title' => 'Héberger un site', 'content' => str_repeat('mot ', 300), 'metaDescription' => 'Pourquoi.']));

        $post = $this->onlyPost();
        self::assertSame('heberger-un-site', $post->getSlug());
        self::assertSame('fr', $post->getLocale());
        self::assertSame('Pourquoi.', $post->getMetaDescription());
        self::assertSame(2, $post->getReadingTime());
        self::assertFalse($post->isPublished());
        self::assertSame('/admin/blog/'.$post->getId(), $this->redirect($response));
        self::assertSame(['Article créé.'], $this->flashes('success'));
    }

    public function testACoverSentWithTheArticleIsStoredForIt(): void
    {
        $this->controller()->new($this->submit(['title' => 'Illustré', 'content' => 'Texte.'], ['featuredImage' => $this->png('cover.png', 1200, 675)]));

        self::assertSame('cover.webp', $this->onlyPost()->getFeaturedImage()?->getFilename());
    }

    /**
     * The browser crops a cover of another size; one that reaches the server anyway is
     * refused, naming both sizes.
     */
    public function testACoverOfAnotherSizeIsRefused(): void
    {
        $response = $this->controller()->new($this->submit(['title' => 'Illustré', 'content' => 'Texte.'], ['featuredImage' => $this->png('cover.png', 1600, 900)]));

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertStringContainsString('doit mesurer exactement 1200×675 px, celle-ci mesure 1600×900 px', (string) $response->getContent());
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM blog_posts'));
    }

    public function testAnArticleWithoutTitleNorContentIsRefused(): void
    {
        $response = $this->controller()->new($this->submit(['title' => '', 'content' => '']));

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertStringContainsString('Le titre est obligatoire.', (string) $response->getContent());
        self::assertStringContainsString('Le contenu est obligatoire.', (string) $response->getContent());
    }

    public function testASlugTypedByHandMustBeFreeInTheLanguage(): void
    {
        $this->post('Existant', slug: 'bienvenue');

        $response = $this->controller()->new($this->submit(['title' => 'Bienvenue', 'slug' => 'bienvenue', 'content' => 'Texte.']));

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertStringContainsString('Le slug « bienvenue » est déjà pris dans cette langue.', (string) $response->getContent());
    }

    public function testASlugTypedByHandMustBeAnAddress(): void
    {
        $response = $this->controller()->new($this->submit(['title' => 'Bienvenue', 'slug' => 'Pas une adresse', 'content' => 'Texte.']));

        self::assertStringContainsString('Le slug ne contient que des lettres minuscules, des chiffres et des tirets.', (string) $response->getContent());
    }

    public function testEditingAnArticleKeepsItsSlugUnlessChanged(): void
    {
        $post = $this->post('Bienvenue', slug: 'bienvenue');

        $this->controller()->edit($this->submit(['title' => 'Bienvenue à tous', 'slug' => 'bienvenue', 'content' => 'Nouveau.']), (int) $post->getId());

        self::assertSame('bienvenue', $post->getSlug());
        self::assertSame('Bienvenue à tous', $post->getTitle());
        self::assertSame(['Article enregistré.'], $this->flashes('success'));
    }

    public function testTheEditPageOffersPublicationAndTheFiles(): void
    {
        $post = $this->post('Brouillon', publishedAt: null);

        $page = $this->crawl($this->controller()->edit($this->request(), (int) $post->getId()));

        self::assertSame('Modifier l\'article', $page->filter('h1')->text());
        self::assertSame('Publier', $page->filter('form[action$="/publish"] button')->text());
        self::assertSame(self::TOKEN, $page->filter('form[action$="/publish"] input[name=_token]')->attr('value'));
        self::assertStringContainsString('« Brouillon »', (string) $page->filter('form[action$="/delete"]')->attr('data-mulertech-blog-confirm'));
        self::assertSame('Fichiers joints', $page->filter('section h2')->text());
    }

    public function testAPublishedArticleOffersToBeSeenAndUnpublished(): void
    {
        $post = $this->post('Publié', slug: 'publie');

        $page = $this->crawl($this->controller()->edit($this->request(), (int) $post->getId()));

        self::assertSame('Dépublier', $page->filter('form[action$="/unpublish"] button')->text());
        self::assertSame('/fr/blog/publie', $page->filter('a[target=_blank]')->last()->attr('href'));
        self::assertSame(sprintf('/admin/blog/%d/preview', $post->getId()), $page->filter('a[target=_blank]')->first()->attr('href'));
    }

    public function testTheCoverOfAnArticleCanBeRemoved(): void
    {
        $post = $this->post('Illustré', slug: 'illustre');
        $this->media()->setCover($post, $this->png('cover.png', 1200, 675));

        $page = $this->crawl($this->controller()->edit($this->request(), (int) $post->getId()));
        self::assertSame(sprintf('/admin/blog/%d/files/%d', $post->getId(), $post->getFeaturedImage()?->getId()), $page->filter('form img')->attr('src'));

        $this->controller()->edit($this->submit(['title' => 'Illustré', 'slug' => $post->getSlug(), 'content' => 'Texte.', 'removeFeaturedImage' => '1']), (int) $post->getId());

        self::assertNull($post->getFeaturedImage());
    }

    public function testTheFieldsOfTheSiteFormAreSavedToo(): void
    {
        $page = $this->crawl($this->controller(formType: ExtendedBlogPostType::class)->new($this->request()));
        self::assertCount(1, $page->filter('[name="extended_blog_post[metaDescription]"]'));
        self::assertCount(1, $page->filter('[name="extended_blog_post[excerpt]"]'));

        $this->controller(formType: ExtendedBlogPostType::class)->new($this->submit(['title' => 'Avec extrait', 'content' => 'Texte.', 'excerpt' => 'Résumé.'], name: 'extended_blog_post'));

        self::assertSame('Résumé.', $this->onlyPost()->getExcerpt());
    }

    public function testPublishingAndUnpublishingKeepTheFirstDate(): void
    {
        $post = $this->post('Brouillon', publishedAt: null);

        $response = $this->controller()->publish($this->post_(['_token' => self::TOKEN]), (int) $post->getId());
        self::assertTrue($post->isPublished());
        self::assertEquals(new \DateTimeImmutable('2026-06-01 09:00'), $post->getPublishedAt());
        self::assertSame('/admin/blog/'.$post->getId(), $this->redirect($response));

        $this->controller()->unpublish($this->post_(['_token' => self::TOKEN]), (int) $post->getId());
        self::assertFalse($post->isPublished());
        self::assertSame(['Article publié.', 'Article dépublié.'], $this->flashes('success'));
    }

    public function testDeletingAnArticleRemovesItAndItsFiles(): void
    {
        $post = $this->post('À supprimer');
        $this->media()->setCover($post, $this->png('cover.png', 1200, 675));

        $response = $this->controller()->delete($this->post_(['_token' => self::TOKEN]), (int) $post->getId());

        self::assertSame('/admin/blog', $this->redirect($response));
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM blog_posts'));
        self::assertSame([], glob($this->fileManager->directory.'/*'));
    }

    public function testAFileIsAttachedThenInsertedAsAReference(): void
    {
        $post = $this->post('Avec fichier');

        $this->controller()->addFile($this->post_(['_token' => self::TOKEN], ['file' => $this->png('schéma.png', 10, 10)]), (int) $post->getId());

        $file = $post->getFiles()->first();
        self::assertInstanceOf(File::class, $file);
        self::assertSame(['« schéma.png » est joint à l\'article.'], $this->flashes('success'));

        $page = $this->crawl($this->controller()->edit($this->request(), (int) $post->getId()));
        self::assertSame(sprintf('![schéma.webp](blog-file:%d)', $file->getId()), $page->filter('[data-mulertech-blog-insert]')->attr('data-mulertech-blog-insert'));
    }

    public function testADocumentIsInsertedAsALink(): void
    {
        $post = $this->post('Avec document');
        $file = $this->media()->attach($post, $this->pdf('devis.pdf'));

        $page = $this->crawl($this->controller()->edit($this->request(), (int) $post->getId()));

        self::assertSame(sprintf('[devis.pdf](blog-file:%d)', $file->getId()), $page->filter('[data-mulertech-blog-insert]')->attr('data-mulertech-blog-insert'));
    }

    public function testAFileRefusedByTheStorageSaysWhy(): void
    {
        $post = $this->post('Avec fichier');
        $path = (string) tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, 'binary');

        $this->controller()->addFile($this->post_(['_token' => self::TOKEN], ['file' => new UploadedFile($path, 'virus.exe', null, test: true)]), (int) $post->getId());

        self::assertSame(['« virus.exe » n\'a pas pu être joint : File type "exe" is not allowed.'], $this->flashes('error'));
        self::assertCount(0, $post->getFiles());
    }

    public function testSendingNoFileSaysSo(): void
    {
        $post = $this->post('Avec fichier');

        $this->controller()->addFile($this->post_(['_token' => self::TOKEN]), (int) $post->getId());

        self::assertSame(['Choisissez un fichier à joindre.'], $this->flashes('error'));
    }

    public function testAnAttachedFileIsRemovedAndDeleted(): void
    {
        $post = $this->post('Avec fichier');
        $file = $this->media()->attach($post, $this->png('schéma.png', 10, 10));

        $this->controller()->removeFile($this->post_(['_token' => self::TOKEN]), (int) $post->getId(), (int) $file->getId());

        self::assertCount(0, $post->getFiles());
        self::assertSame(['« schéma.webp » est retiré et supprimé.'], $this->flashes('success'));
    }

    public function testAFileOfAnotherArticleCannotBeRemovedThroughThisOne(): void
    {
        $post = $this->post('Sans fichier');
        $file = $this->media()->attach($this->post('Autre'), $this->png('a.png', 10, 10));

        $this->expectException(NotFoundHttpException::class);

        $this->controller()->removeFile($this->post_(['_token' => self::TOKEN]), (int) $post->getId(), (int) $file->getId());
    }

    /**
     * A draft's images show in the admin, and never reach a shared cache.
     */
    public function testTheFilesOfADraftAreServedToTheAdminOnly(): void
    {
        $draft = $this->post('Brouillon', publishedAt: null);
        $this->media()->setCover($draft, $this->png('cover.png', 1200, 675));
        $file = $this->media()->attach($draft, $this->pdf('devis.pdf'));

        $cover = $this->controller()->file((int) $draft->getId(), (int) $draft->getFeaturedImage()?->getId());
        self::assertInstanceOf(BinaryFileResponse::class, $cover);
        self::assertSame('image/webp', $cover->headers->get('Content-Type'));
        self::assertTrue($cover->headers->hasCacheControlDirective('no-store'));

        self::assertSame('application/pdf', $this->controller()->file((int) $draft->getId(), (int) $file->getId())->headers->get('Content-Type'));
    }

    public function testAnUnknownArticleAnswersNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->controller()->edit($this->request(), 999);
    }

    /**
     * @param \Closure(BlogAdminController): Response $action
     */
    #[DataProvider('actions')]
    public function testEveryActionRequiresTheAdminRole(\Closure $action): void
    {
        $this->granted = false;

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('The blog administration requires ROLE_ADMIN.');

        $action($this->controller());
    }

    /**
     * @return iterable<string, array{\Closure(BlogAdminController): Response}>
     */
    public static function actions(): iterable
    {
        yield 'list' => [static fn (BlogAdminController $controller): Response => $controller->index(new Request())];
        yield 'create' => [static fn (BlogAdminController $controller): Response => $controller->new(new Request())];
        yield 'edit' => [static fn (BlogAdminController $controller): Response => $controller->edit(new Request(), 1)];
        yield 'delete' => [static fn (BlogAdminController $controller): Response => $controller->delete(new Request(), 1)];
        yield 'publish' => [static fn (BlogAdminController $controller): Response => $controller->publish(new Request(), 1)];
        yield 'unpublish' => [static fn (BlogAdminController $controller): Response => $controller->unpublish(new Request(), 1)];
        yield 'attach' => [static fn (BlogAdminController $controller): Response => $controller->addFile(new Request(), 1)];
        yield 'detach' => [static fn (BlogAdminController $controller): Response => $controller->removeFile(new Request(), 1, 1)];
        yield 'file' => [static fn (BlogAdminController $controller): Response => $controller->file(1, 1)];
    }

    /**
     * @param \Closure(BlogAdminController, Request, int): Response $action
     */
    #[DataProvider('changes')]
    public function testEveryChangeRequiresAValidToken(\Closure $action): void
    {
        $post = $this->post('Article');

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Invalid CSRF token');

        $action($this->controller(), $this->post_(['_token' => 'forged']), (int) $post->getId());
    }

    /**
     * @return iterable<string, array{\Closure(BlogAdminController, Request, int): Response}>
     */
    public static function changes(): iterable
    {
        yield 'delete' => [static fn (BlogAdminController $controller, Request $request, int $id): Response => $controller->delete($request, $id)];
        yield 'publish' => [static fn (BlogAdminController $controller, Request $request, int $id): Response => $controller->publish($request, $id)];
        yield 'unpublish' => [static fn (BlogAdminController $controller, Request $request, int $id): Response => $controller->unpublish($request, $id)];
        yield 'attach' => [static fn (BlogAdminController $controller, Request $request, int $id): Response => $controller->addFile($request, $id)];
        yield 'detach' => [static fn (BlogAdminController $controller, Request $request, int $id): Response => $controller->removeFile($request, $id, 1)];
    }

    /**
     * @param list<string> $locales
     */
    private function controller(array $locales = ['fr'], string $formType = BlogPostType::class): BlogAdminController
    {
        $validator = Validation::createValidatorBuilder()
            ->setTranslator($this->translator)
            ->setTranslationDomain('validators')
            ->getValidator();
        $formFactory = Forms::createFormFactoryBuilder()
            ->addExtension(new HttpFoundationExtension())
            ->addExtension(new ValidatorExtension($validator))
            ->getFormFactory();

        $authorizationChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturn($this->granted);
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(static fn (CsrfToken $token): bool => self::TOKEN === $token->getValue());

        return new BlogAdminController(
            $this->repository(),
            $this->entityManager,
            $this->media(),
            new BlogPostPublisher($this->entityManager, new MockClock('2026-06-01 09:00')),
            new SlugGenerator($this->repository()),
            $this->fileManager,
            $formFactory,
            $this->twig,
            $this->urlGenerator,
            $authorizationChecker,
            $csrf,
            $this->translator,
            BlogPost::class,
            $formType,
            $locales,
            'base.html.twig',
            'ROLE_ADMIN',
        );
    }

    private function media(): BlogMediaManager
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(new AdminUser(), 'main', ['ROLE_ADMIN']));

        return new BlogMediaManager($this->fileManager, $tokenStorage, $this->entityManager);
    }

    /**
     * @param array<string, string>       $fields
     * @param array<string, UploadedFile> $files
     */
    private function submit(array $fields, array $files = [], string $name = 'blog_post'): Request
    {
        return $this->post_([$name => $fields], [$name => $files]);
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $files
     */
    private function post_(array $fields, array $files = []): Request
    {
        $request = new Request(request: $fields, files: $files, server: ['REQUEST_METHOD' => 'POST']);
        $request->setLocale('fr');
        $request->setSession($this->session ??= new Session(new MockArraySessionStorage()));
        $this->requestStack->push($request);

        return $request;
    }

    private ?Session $session = null;

    /**
     * @return list<string>
     */
    private function flashes(string $type): array
    {
        /** @var list<string> */
        return $this->session?->getFlashBag()->peek($type) ?? [];
    }

    private function crawl(Response $response): Crawler
    {
        return new Crawler((string) $response->getContent());
    }

    private function redirect(Response $response): string
    {
        self::assertInstanceOf(RedirectResponse::class, $response);

        return $response->getTargetUrl();
    }

    private function onlyPost(): BlogPost
    {
        $posts = $this->entityManager->getRepository(BlogPost::class)->findAll();
        self::assertCount(1, $posts);

        return $posts[0];
    }

    private function png(string $name, int $width, int $height): UploadedFile
    {
        \assert($width > 0 && $height > 0);
        $path = (string) tempnam(sys_get_temp_dir(), 'upload');
        imagepng(imagecreatetruecolor($width, $height), $path);

        return new UploadedFile($path, $name, 'image/png', test: true);
    }

    private function pdf(string $name): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, "%PDF-1.4\n%%EOF");

        return new UploadedFile($path, $name, 'application/pdf', test: true);
    }
}
