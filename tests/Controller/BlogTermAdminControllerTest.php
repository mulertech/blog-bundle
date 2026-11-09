<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Controller;

use MulerTech\BlogBundle\Controller\BlogTermAdminController;
use MulerTech\BlogBundle\Form\BlogTermType;
use MulerTech\BlogBundle\Tests\Fixtures\ExtendedBlogTermType;
use MulerTech\BlogBundle\Tests\Fixtures\Entity\BlogCategory;
use MulerTech\BlogBundle\Tests\Fixtures\Entity\BlogTag;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class BlogTermAdminControllerTest extends AdminPageTestCase
{
    public function testTheListShowsEachCategoryWithItsArticles(): void
    {
        $hosting = $this->category('Hébergement');
        $this->category('Design');
        $this->post('Un')->setCategory($hosting);
        $this->post('Brouillon', publishedAt: null)->setCategory($hosting);
        $this->entityManager->flush();

        $page = $this->crawl($this->categoryController()->index());

        self::assertSame('Catégories', $page->filter('h1')->text());
        self::assertSame(
            [['Design', '0'], ['Hébergement', '2']],
            $page->filter('tbody tr')->each(static fn (Crawler $row): array => [$row->filter('td')->eq(0)->text(), $row->filter('td')->eq(1)->text()]),
        );
        self::assertSame('/admin/blog/categories/new', $page->filter('a[href$="/new"]')->attr('href'));
    }

    public function testACategoryIsCreatedWithASlugFromItsName(): void
    {
        $response = $this->categoryController()->new($this->submit(['name' => 'Hébergement web', 'slug' => '', 'description' => 'Serveurs.']));

        self::assertSame('/admin/blog/categories', $this->redirect($response));
        $category = $this->entityManager->getRepository(BlogCategory::class)->findOneBy([]);
        self::assertInstanceOf(BlogCategory::class, $category);
        self::assertSame(['Hébergement web', 'hebergement-web', 'fr', 'Serveurs.'], [$category->getName(), $category->getSlug(), $category->getLocale(), $category->getDescription()]);
        self::assertSame(['Ajout enregistré : « Hébergement web ».'], $this->flashes('success'));
    }

    public function testTheFieldsOfTheSiteCategoryFormAreSavedToo(): void
    {
        $controller = $this->controller('category', $this->categories(), ['fr'], ExtendedBlogTermType::class);

        self::assertCount(1, $this->crawl($controller->new($this->request()))->filter('[name="extended_blog_term[color]"]'));

        $controller->new($this->post_(['extended_blog_term' => ['name' => 'Hébergement', 'slug' => '', 'color' => 'blue']]));

        self::assertSame('blue', $this->entityManager->getRepository(BlogCategory::class)->findOneBy([])?->getColor());
    }

    public function testATagHasNoDescription(): void
    {
        $page = $this->crawl($this->tagController()->new($this->request()));

        self::assertSame('Nouveau tag', $page->filter('h1')->text());
        self::assertCount(1, $page->filter('[name="blog_term[name]"]'));
        self::assertCount(0, $page->filter('[name="blog_term[description]"]'));
    }

    public function testASlugTakenInTheLanguageIsRefused(): void
    {
        $this->tag('Sécurité')->setSlug('securite');
        $this->tag('Security', 'en')->setSlug('securite');
        $this->entityManager->flush();

        $response = $this->tagController()->new($this->submit(['name' => 'Sécurité', 'slug' => '']));

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertStringContainsString('Le slug « securite » est déjà pris dans cette langue.', $this->crawl($response)->filter('input[name="blog_term[name]"]')->ancestors()->first()->text());

        $typed = $this->tagController()->new($this->submit(['name' => 'Autre', 'slug' => 'securite']));
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $typed->getStatusCode());
        self::assertStringContainsString('Le slug « securite » est déjà pris dans cette langue.', $this->crawl($typed)->filter('input[name="blog_term[slug]"]')->ancestors()->first()->text());
    }

    public function testANameWithoutLetterNorDigitIsRefused(): void
    {
        $response = $this->tagController()->new($this->submit(['name' => '!!!', 'slug' => '']));

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertStringContainsString('Le slug ne contient que des lettres minuscules', $this->crawl($response)->text());
    }

    public function testATermIsEditedKeepingItsOwnSlug(): void
    {
        $tag = $this->tag('Sécurité')->setSlug('securite');
        $this->entityManager->flush();

        $page = $this->crawl($this->tagController()->edit($this->request(), (int) $tag->getId()));
        self::assertSame('Modifier le tag', $page->filter('h1')->text());

        $response = $this->tagController()->edit($this->submit(['name' => 'Sécurité web', 'slug' => 'securite']), (int) $tag->getId());

        self::assertSame('/admin/blog/tags', $this->redirect($response));
        self::assertSame('Sécurité web', $tag->getName());
        self::assertSame(['Modification enregistrée : « Sécurité web ».'], $this->flashes('success'));
    }

    public function testATagIsDeletedAndLeavesItsArticles(): void
    {
        $tag = $this->tag('Sécurité');
        $post = $this->post('Un')->addTag($tag);
        $this->entityManager->flush();
        $id = (int) $tag->getId();

        $this->tagController()->delete($this->post_(['_token' => self::TOKEN]), $id);
        $this->entityManager->clear();

        self::assertNull($this->entityManager->find(BlogTag::class, $id));
        self::assertCount(1, $this->repository()->findAllPublished());
        self::assertSame(['Suppression effectuée : « Sécurité ».'], $this->flashes('success'));
        self::assertNotNull($post->getId());
    }

    /**
     * Deleting it would leave its articles without category, which the site may not allow.
     */
    public function testACategoryHoldingArticlesIsKept(): void
    {
        $hosting = $this->category('Hébergement');
        $this->post('Un')->setCategory($hosting);
        $this->entityManager->flush();

        $this->categoryController()->delete($this->post_(['_token' => self::TOKEN]), (int) $hosting->getId());

        self::assertNotNull($this->entityManager->find(BlogCategory::class, $hosting->getId()));
        self::assertSame(['« Hébergement » classe encore 1 article(s) : changez leur catégorie avant de la supprimer.'], $this->flashes('error'));

        $empty = $this->category('Vide');
        $this->categoryController()->delete($this->post_(['_token' => self::TOKEN]), (int) $empty->getId());
        self::assertSame(['Suppression effectuée : « Vide ».'], $this->flashes('success'));
    }

    public function testADeletionWithoutValidTokenIsRefused(): void
    {
        $tag = $this->tag('Sécurité');

        $this->expectException(AccessDeniedException::class);

        $this->tagController()->delete($this->post_(['_token' => 'forged']), (int) $tag->getId());
    }

    public function testAnUnknownTermAnswersNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->tagController()->edit($this->request(), 999);
    }

    public function testASiteWithoutCategoriesAnswersNotFoundNamingTheKey(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('The blog has no categories: set mulertech_blog.category_class to enable them.');

        $this->categoryController(enabled: false)->index();
    }

    /**
     * @return iterable<string, array{\Closure(BlogTermAdminController): Response}>
     */
    public static function actions(): iterable
    {
        yield 'list' => [static fn (BlogTermAdminController $controller): Response => $controller->index()];
        yield 'new' => [static fn (BlogTermAdminController $controller): Response => $controller->new(new \Symfony\Component\HttpFoundation\Request())];
        yield 'edit' => [static fn (BlogTermAdminController $controller): Response => $controller->edit(new \Symfony\Component\HttpFoundation\Request(), 1)];
        yield 'delete' => [static fn (BlogTermAdminController $controller): Response => $controller->delete(new \Symfony\Component\HttpFoundation\Request(), 1)];
    }

    /**
     * @param \Closure(BlogTermAdminController): Response $action
     */
    #[DataProvider('actions')]
    public function testEveryActionRequiresTheAdminRole(\Closure $action): void
    {
        $this->granted = false;

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('The blog administration requires ROLE_ADMIN.');

        $action($this->tagController());
    }

    public function testASiteWithSeveralLanguagesChoosesTheLanguageOfATerm(): void
    {
        $this->tagController(['fr', 'en'])->new($this->submit(['name' => 'Security', 'slug' => '', 'locale' => 'en']));

        $tag = $this->entityManager->getRepository(BlogTag::class)->findOneBy([]);
        self::assertSame('en', $tag?->getLocale());
        self::assertSame('en', $this->crawl($this->tagController(['fr', 'en'])->index())->filter('tbody td')->eq(1)->text());
    }

    /**
     * @param array<string, string> $fields
     */
    private function submit(array $fields): \Symfony\Component\HttpFoundation\Request
    {
        return $this->post_(['blog_term' => $fields]);
    }

    /**
     * @param list<string> $locales
     */
    private function categoryController(bool $enabled = true, array $locales = ['fr']): BlogTermAdminController
    {
        return $this->controller('category', $this->categories($enabled), $locales);
    }

    /**
     * @param list<string> $locales
     */
    private function tagController(array $locales = ['fr']): BlogTermAdminController
    {
        return $this->controller('tag', $this->tags(), $locales);
    }

    /**
     * @param 'category'|'tag' $kind
     * @param list<string>     $locales
     * @param class-string     $formType
     */
    private function controller(string $kind, \MulerTech\BlogBundle\Repository\BlogTermRepository $terms, array $locales, string $formType = BlogTermType::class): BlogTermAdminController
    {
        return new BlogTermAdminController(
            $terms,
            $this->repository(),
            $kind,
            $formType,
            $this->entityManager,
            $this->formFactory(),
            $this->twig,
            $this->urlGenerator,
            $this->authorizationChecker(),
            $this->csrfTokenManager(),
            $this->translator,
            $locales,
            'base.html.twig',
            'ROLE_ADMIN',
        );
    }
}
