<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Controller;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use MulerTech\BlogBundle\Controller\BlogController;
use MulerTech\BlogBundle\Seo\BlogPostSocialCardProvider;
use MulerTech\BlogBundle\Service\ViewCounter;
use MulerTech\BlogBundle\Tests\Fixtures\Entity\BlogPost;
use MulerTech\BlogBundle\Tests\Fixtures\Entity\File;
use MulerTech\FileBundle\Model\FileInterface;
use MulerTech\FileBundle\Service\FileManagerInterface;
use MulerTech\SeoBundle\Service\SocialCardService;
use Psr\Log\NullLogger;
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
    }

    /**
     * Whether a layout leaves its `seo_meta` block empty or fills it, the page carries its
     * meta tags exactly once.
     */
    public function testThePageReplacesTheMetaTagsOfTheLayout(): void
    {
        $this->post('Bienvenue', slug: 'bienvenue');

        $page = $this->show('bienvenue');

        self::assertCount(0, $page->filter('meta[name=site-meta]'));
        self::assertCount(1, $page->filter('meta[property="og:title"]'));
        self::assertCount(1, $page->filter('link[rel=canonical]'));
    }

    public function testTheArticleDescribesItselfToSearchEnginesAndPlatforms(): void
    {
        $post = $this->post('Héberger un site', publishedAt: '2026-03-14 10:00', slug: 'heberger-un-site');
        $post->setMetaDescription('Pourquoi un hébergeur en France.');
        $this->entityManager->flush();

        $page = $this->show('heberger-un-site', '/fr/blog/heberger-un-site');

        self::assertSame('Héberger un site', $page->filter('meta[property="og:title"]')->attr('content'));
        self::assertSame('Pourquoi un hébergeur en France.', $page->filter('meta[property="og:description"]')->attr('content'));
        self::assertSame('article', $page->filter('meta[property="og:type"]')->attr('content'));
        self::assertSame('https://example.com/fr/blog/heberger-un-site', $page->filter('link[rel=canonical]')->attr('href'));
        self::assertSame('2026-03-14T10:00:00+00:00', $page->filter('meta[property="article:published_time"]')->attr('content'));
        self::assertSame('Jeanne Martin', $page->filter('meta[property="article:author"]')->attr('content'));
    }

    /**
     * Platforms want a 1200x630 JPEG; seo-bundle derives it from the featured image.
     */
    public function testTheArticleIsSharedWithTheCardOfItsFeaturedImage(): void
    {
        $post = $this->post('Illustré', slug: 'illustre');
        $image = $this->file('cover.webp', 'image/webp');
        $post->setFeaturedImage($image);
        $this->entityManager->flush();

        $page = $this->show('illustre');

        self::assertMatchesRegularExpression(
            sprintf('#^https://example\.com/social-card/blog/image-%d-[0-9a-f]{8}\.jpg$#', $post->getId()),
            (string) $page->filter('meta[property="og:image"]')->attr('content'),
        );
        self::assertSame('1200', $page->filter('meta[property="og:image:width"]')->attr('content'));
        self::assertSame('630', $page->filter('meta[property="og:image:height"]')->attr('content'));
        self::assertSame('image/jpeg', $page->filter('meta[property="og:image:type"]')->attr('content'));
        self::assertSame('Illustré', $page->filter('meta[property="og:image:alt"]')->attr('content'));
    }

    public function testAnArticleWithoutImageIsSharedWithTheSiteDefaultImage(): void
    {
        $this->post('Bienvenue', slug: 'bienvenue');

        self::assertSame('https://example.com/images/og-default.jpg', $this->show('bienvenue')->filter('meta[property="og:image"]')->attr('content'));
    }

    public function testTheArticleDeclaresItselfAsABlogPosting(): void
    {
        $this->post('Bienvenue', publishedAt: '2026-03-14 10:00', slug: 'bienvenue');

        $data = json_decode((string) $this->show('bienvenue')->filter('script[type="application/ld+json"]')->text(), true);

        self::assertIsArray($data);
        self::assertSame('BlogPosting', $data['@type']);
        self::assertSame('Bienvenue', $data['headline']);
        self::assertSame(['@type' => 'Person', 'name' => 'Jeanne Martin'], $data['author']);
        self::assertSame('2026-03-14T10:00:00+00:00', $data['datePublished']);
    }

    public function testTheListDescribesItself(): void
    {
        $page = $this->index();

        self::assertSame('Blog', $page->filter('meta[property="og:title"]')->attr('content'));
        self::assertSame('Les articles du blog.', $page->filter('meta[property="og:description"]')->attr('content'));
        self::assertSame('https://example.com/fr/blog', $page->filter('link[rel=canonical]')->attr('href'));
    }

    /**
     * Every query typed would otherwise be a page of its own for search engines.
     */
    public function testASearchPointsSearchEnginesAtTheList(): void
    {
        $this->post('Facturation');

        self::assertSame('https://example.com/fr/blog', $this->index(['q' => 'facturation'])->filter('link[rel=canonical]')->attr('href'));
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

    private function show(string $slug, string $path = '/fr/blog/article'): Crawler
    {
        return new Crawler((string) $this->controller()->show($this->request(path: $path), $slug)->getContent());
    }

    private function controller(): BlogController
    {
        $fileManager = $this->createStub(FileManagerInterface::class);
        $fileManager->method('getAbsolutePath')->willReturnCallback(static fn (FileInterface $file): string => '/storage/'.$file->getStoredFilename());
        $socialCards = new BlogPostSocialCardProvider($this->repository(), $fileManager);

        return new BlogController(
            $this->repository(),
            new ViewCounter($this->entityManager, $this->requestStack),
            $this->twig,
            $this->metaTagService,
            new SocialCardService([$socialCards], $this->urlGenerator, new NullLogger(), sys_get_temp_dir(), 0xFFFFFF),
            $socialCards,
            $this->postUrls(),
            $this->translator,
            $this->createStub(AuthorizationCheckerInterface::class),
            $this->urlGenerator,
            'base.html.twig',
            'ROLE_ADMIN',
        );
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
