<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Controller;

use MulerTech\BlogBundle\Controller\BlogController;
use MulerTech\BlogBundle\Service\ViewCounter;
use MulerTech\BlogBundle\Tests\Fixtures\Entity\BlogPost;
use MulerTech\BlogBundle\Tests\Fixtures\Entity\File;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class BlogControllerTest extends PublicPageTestCase
{
    public function testTheListShowsACardPerArticleWithItsDetails(): void
    {
        $post = $this->post('Héberger un site', publishedAt: '2026-03-14 10:00', content: str_repeat('mot ', 450), slug: 'heberger-un-site');
        $this->setViewCount($post, 12);
        $this->entityManager->refresh($post);

        $page = $this->index();

        self::assertSame('Blog', $page->filter('title')->text());
        $card = $page->filter('article')->first();
        self::assertSame('Héberger un site', $card->filter('h2')->text());
        self::assertStringContainsString('14/03/2026', $card->text());
        self::assertStringContainsString('3 min', $card->text());
        self::assertStringContainsString('12 vues', $card->text());
        self::assertSame('/fr/blog/heberger-un-site', $card->filter('a')->last()->attr('href'));
        self::assertStringContainsString('Lire la suite', $card->filter('a')->last()->text());
    }

    public function testTheCardThumbnailIsTheFeaturedImageServedThroughItsArticle(): void
    {
        $post = $this->post('Illustré', slug: 'illustre');
        $image = $this->file('cover.webp', 'image/webp');
        $post->setFeaturedImage($image);
        $this->entityManager->flush();

        $image = $this->index()->filter('article img');

        self::assertSame('/fr/blog/illustre/files/'.$post->getFeaturedImage()?->getId(), $image->attr('src'));
        self::assertStringContainsString('aspect-square', (string) $this->index()->filter('article a')->first()->attr('class'));
    }

    public function testTheSidebarListsTheRecentAndPopularArticles(): void
    {
        $this->setViewCount($this->post('Ancien et lu', publishedAt: '2026-01-01'), 40);
        $this->post('Récent', publishedAt: '2026-05-01');
        $this->entityManager->clear();

        $sidebar = $this->index()->filter('aside section');

        self::assertSame('Articles récents', $sidebar->eq(0)->filter('h2')->text());
        self::assertSame('Récent', $sidebar->eq(0)->filter('li span')->first()->text());
        self::assertSame('Articles populaires', $sidebar->eq(1)->filter('h2')->text());
        self::assertSame('Ancien et lu', $sidebar->eq(1)->filter('li span')->first()->text());
    }

    public function testTheListIsPaginatedByTwelve(): void
    {
        foreach (range(1, 13) as $number) {
            $this->post('Article '.$number, publishedAt: sprintf('2026-01-%02d', $number));
        }

        $first = $this->index();
        self::assertCount(12, $first->filter('article'));
        self::assertSame('/fr/blog?page=2', $first->filter('nav a[rel=next]')->attr('href'));
        self::assertCount(0, $first->filter('nav a[rel=prev]'));

        $second = $this->index(['page' => '2']);
        self::assertSame(['Article 1'], $second->filter('article h2')->each(static fn (Crawler $title): string => $title->text()));
        self::assertSame('/fr/blog', $second->filter('nav a[rel=prev]')->attr('href'), 'the first page has no number in its address');
    }

    public function testTheSearchFiltersTheListAndFollowsThePagination(): void
    {
        foreach (range(1, 13) as $number) {
            $this->post('Facturation '.$number, publishedAt: sprintf('2026-01-%02d', $number));
        }
        $this->post('Autre sujet');

        $page = $this->index(['q' => ' facturation ']);

        self::assertCount(12, $page->filter('article'));
        self::assertSame('facturation', $page->filter('input[name=q]')->attr('value'));
        self::assertStringContainsString('Résultats pour « facturation »', $page->text());
        self::assertSame('/fr/blog?q=facturation&page=2', $page->filter('nav a[rel=next]')->attr('href'));
    }

    public function testASearchWithoutResultSaysSoAndLeadsBackToTheList(): void
    {
        $this->post('Sujet');

        $page = $this->index(['q' => 'introuvable']);

        self::assertStringContainsString('Aucun article trouvé.', $page->text());
        self::assertSame('Voir tous les articles', $page->filter('a[href="/fr/blog"]')->last()->text());
    }

    public function testAnEmptyBlogStillHasItsListPage(): void
    {
        self::assertStringContainsString('Aucun article trouvé.', $this->index()->text());
    }

    /**
     * @param array<string, string> $query
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('pagesThatDoNotExist')]
    public function testAPageThatDoesNotExistAnswersNotFound(array $query): void
    {
        $this->post('Seul article');

        $this->expectException(NotFoundHttpException::class);

        $this->controller()->index($this->request($query));
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function pagesThatDoNotExist(): iterable
    {
        yield 'beyond the last' => [['page' => '2']];
        yield 'zero' => [['page' => '0']];
        yield 'negative' => [['page' => '-1']];
        yield 'not a number' => [['page' => 'deux']];
        yield 'empty' => [['page' => '']];
    }

    public function testTheArticleShowsItsMarkdownContentFullWidth(): void
    {
        $this->post('Héberger un site', publishedAt: '2026-03-14 10:00', content: "## Pourquoi\n\nUn **bon** hébergeur.", slug: 'heberger-un-site');

        $page = $this->show('heberger-un-site');

        self::assertSame('Héberger un site', $page->filter('title')->text());
        self::assertSame('Héberger un site', $page->filter('h1')->text());
        self::assertSame('Pourquoi', $page->filter('.prose h2')->text());
        self::assertSame('bon', $page->filter('.prose strong')->text());
        self::assertStringContainsString('Publié le 14/03/2026', $page->text());
        self::assertCount(0, $page->filter('aside'));
    }

    public function testTheArticleOffersSharingOnLinkedInXAndFacebook(): void
    {
        $this->post('Bienvenue', slug: 'bienvenue');

        $share = $this->show('bienvenue')->filter('section');

        self::assertSame('Partager cet article', $share->filter('h2')->text());
        self::assertSame([
            'https://www.linkedin.com/sharing/share-offsite/?url=https%3A%2F%2Fexample.com%2Ffr%2Fblog%2Fbienvenue',
            'https://x.com/intent/post?url=https%3A%2F%2Fexample.com%2Ffr%2Fblog%2Fbienvenue&text=Bienvenue',
            'https://www.facebook.com/sharer/sharer.php?u=https%3A%2F%2Fexample.com%2Ffr%2Fblog%2Fbienvenue',
        ], $share->filter('a')->each(static fn (Crawler $link): ?string => $link->attr('href')));
    }

    public function testTheArticleLeadsToItsNeighboursAndBackToTheList(): void
    {
        $this->post('Premier', publishedAt: '2026-01-01', slug: 'premier');
        $this->post('Deuxième', publishedAt: '2026-02-01', slug: 'deuxieme');
        $this->post('Troisième', publishedAt: '2026-03-01', slug: 'troisieme');

        $navigation = $this->show('deuxieme')->filter('nav');

        self::assertSame('/fr/blog/premier', $navigation->filter('a[rel=prev]')->attr('href'));
        self::assertStringContainsString('Article précédent', $navigation->filter('a[rel=prev]')->text());
        self::assertSame('/fr/blog/troisieme', $navigation->filter('a[rel=next]')->attr('href'));
        self::assertStringContainsString('Article suivant', $navigation->filter('a[rel=next]')->text());
        self::assertSame('Retour à la liste', $navigation->filter('a[href="/fr/blog"]')->text());
    }

    public function testReadingTheArticleCountsAView(): void
    {
        $post = $this->post('Bienvenue', slug: 'bienvenue');

        $this->show('bienvenue');

        self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT view_count FROM blog_posts WHERE id = ?', [$post->getId()]));
    }

    /**
     * Search engines are told about every version, the page itself included, so each
     * language is shown to the readers of that language.
     */
    public function testAnArticleAnnouncesItsVersionsInOtherLanguages(): void
    {
        $french = $this->post('Bienvenue', slug: 'bienvenue');
        $english = new BlogPost()->setTitle('Welcome')->setSlug('welcome')->setContent('Text.')->setLocale('en')->setTranslationOf($french)->publish(new \DateTimeImmutable());
        $this->entityManager->persist($english);
        $this->entityManager->flush();

        $links = $this->show('bienvenue')->filter('link[rel=alternate]');

        self::assertSame(['fr', 'en'], $links->each(static fn (Crawler $link): ?string => $link->attr('hreflang')));
        self::assertSame(['https://example.com/fr/blog/bienvenue', 'https://example.com/en/blog/welcome'], $links->each(static fn (Crawler $link): ?string => $link->attr('href')));
        self::assertCount(1, $this->show('bienvenue')->filter('meta[name=site-meta]'), 'the layout\'s own meta tags are kept');
    }

    public function testAnArticleWithoutTranslationAnnouncesNoVersion(): void
    {
        $this->post('Bienvenue', slug: 'bienvenue');

        self::assertCount(0, $this->show('bienvenue')->filter('link[rel=alternate]'));
    }

    public function testADraftAnswersNotFound(): void
    {
        $this->post('Brouillon', publishedAt: null, slug: 'brouillon');

        $this->expectException(NotFoundHttpException::class);

        $this->controller()->show($this->request(), 'brouillon');
    }

    public function testAnArticleOfAnotherLanguageAnswersNotFound(): void
    {
        $this->post('Welcome', 'en', slug: 'welcome');

        $this->expectException(NotFoundHttpException::class);

        $this->controller()->show($this->request(), 'welcome');
    }

    /**
     * @param array<string, string> $query
     */
    private function index(array $query = []): Crawler
    {
        return new Crawler((string) $this->controller()->index($this->request($query))->getContent());
    }

    private function show(string $slug): Crawler
    {
        return new Crawler((string) $this->controller()->show($this->request(), $slug)->getContent());
    }

    private function controller(): BlogController
    {
        return new BlogController($this->repository(), new ViewCounter($this->entityManager, $this->requestStack), $this->twig, 'base.html.twig');
    }

    private function file(string $filename, string $mimeType): File
    {
        $file = new File()
            ->setFilename($filename)
            ->setStoredFilename($filename)
            ->setFilepath('blog/'.$filename)
            ->setMimeType($mimeType)
            ->setSize('100')
            ->setExtension(pathinfo($filename, \PATHINFO_EXTENSION));
        $this->entityManager->persist($file);

        return $file;
    }
}
