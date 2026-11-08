<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Functional;

use MulerTech\BlogBundle\Tests\Functional\App\Entity\Article;
use MulerTech\BlogBundle\Tests\Functional\App\Security\Editor;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * What an editor and a visitor go through, in an application holding the blog alone.
 */
final class BlogTest extends FunctionalTestCase
{
    public function testAnArticleIsWrittenIllustratedPublishedThenRead(): void
    {
        $this->client->loginUser(new Editor());

        $this->client->request('GET', '/admin/blog/new');
        self::assertResponseIsSuccessful();
        $form = $this->client->getCrawler()->selectButton('Enregistrer')->form([
            'blog_post[title]' => 'Héberger son site en France',
            'blog_post[content]' => "## Pourquoi\n\nUn **bon** hébergeur.",
            'blog_post[metaDescription]' => 'Pourquoi un hébergeur en France.',
        ]);
        $form['blog_post[featuredImage]']->upload($this->image(1200, 675));
        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Article créé.');
        self::assertSelectorTextContains('body', 'Brouillon');
        self::assertNotNull($this->article()->getCreatedAt(), 'the lifecycle callbacks of the mapped superclass run');

        $this->client->request('GET', '/blog/heberger-son-site-en-france');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'a draft has no public page');

        $this->client->request('GET', '/admin/blog/'.$this->article()->getId());
        $this->client->submit($this->client->getCrawler()->selectButton('Publier')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Article publié.');

        $page = $this->client->request('GET', '/blog/heberger-son-site-en-france');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Héberger son site en France');
        self::assertSelectorTextSame('.prose strong', 'bon');
        self::assertSame('Pourquoi un hébergeur en France.', $page->filter('meta[property="og:description"]')->attr('content'));

        $this->client->request('GET', (string) $page->filter('article img')->attr('src'));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/webp');

        $this->client->request('GET', '/blog');
        self::assertSelectorTextContains('article h2', 'Héberger son site en France');
    }

    /**
     * The card platforms show is derived by seo-bundle from the featured image.
     */
    public function testAPublishedArticleIsSharedWithACardOfItsImage(): void
    {
        $this->publishedWithCover('Illustré');

        $card = (string) $this->client->request('GET', '/blog/illustre')->filter('meta[property="og:image"]')->attr('content');
        self::assertMatchesRegularExpression('#^http://localhost/social-card/blog/image-\d+-[0-9a-f]{8}\.jpg$#', $card);

        $this->client->request('GET', $card);
        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertInstanceOf(BinaryFileResponse::class, $response);
        $size = getimagesize($response->getFile()->getPathname());
        self::assertIsArray($size);
        self::assertSame([1200, 630, 'image/jpeg'], [$size[0], $size[1], $size['mime']]);
    }

    public function testTheArticleIsListedInTheSitemapAndLlmsTxt(): void
    {
        $this->publishedWithCover('Illustré');

        $this->client->request('GET', '/sitemap.xml');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('<loc>http://localhost/blog</loc>', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('<loc>http://localhost/blog/illustre</loc>', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/llms.txt');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString("## Blog\n", (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('[Illustré](http://localhost/blog/illustre)', (string) $this->client->getResponse()->getContent());
    }

    public function testAnAttachedImageInsertedInTheContentIsShownOnThePage(): void
    {
        $article = $this->publishedWithCover('Illustré');
        $editPage = $this->client->request('GET', '/admin/blog/'.$article->getId());
        $upload = $editPage->selectButton('Joindre')->form();
        $upload['file']->upload($this->image(40, 30));
        $this->client->submit($upload);

        $reference = (string) $this->client->followRedirect()->filter('[data-mulertech-blog-insert]')->attr('data-mulertech-blog-insert');
        self::assertMatchesRegularExpression('/^!\[[^\]]+\]\(blog-file:\d+\)$/', $reference);

        $form = $this->client->getCrawler()->selectButton('Enregistrer')->form();
        $form['blog_post[content]'] = 'Voir le schéma : '.$reference;
        $this->client->submit($form);

        $image = $this->client->request('GET', '/blog/illustre')->filter('.prose img');
        self::assertMatchesRegularExpression('#^/blog/illustre/files/\d+$#', (string) $image->attr('src'));
        $this->client->request('GET', (string) $image->attr('src'));
        self::assertResponseIsSuccessful();
    }

    public function testTheAdministrationIsClosedToAnonymousVisitors(): void
    {
        $this->client->request('GET', '/admin/blog');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /**
     * The structured data script passes the CSP of csp-bundle, which a missing nonce would
     * fail in the browser with nothing on the page to show it.
     */
    public function testTheStructuredDataCarriesTheNonceOfTheSitePolicy(): void
    {
        $this->publishedWithCover('Illustré');

        $script = $this->client->request('GET', '/blog/illustre')->filter('script[type="application/ld+json"]');
        $nonce = (string) $script->attr('nonce');

        self::assertNotSame('', $nonce);
        self::assertStringContainsString("'nonce-".$nonce."'", (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function testTheAdminScriptIsServedByTheSiteAssetMapper(): void
    {
        $this->client->loginUser(new Editor());

        $source = (string) $this->client->request('GET', '/admin/blog/new')->filter('script[type=module]')->attr('src');
        self::assertMatchesRegularExpression('#^/assets/@mulertech/blog-bundle/admin-[\w-]+\.js$#', $source);

        $this->client->request('GET', $source);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('data-cover-cropper', (string) $this->client->getInternalResponse()->getContent());
    }

    /**
     * A site rewords a label in its own translations, without touching the templates.
     */
    public function testTheSiteLabelsTakePrecedenceOverTheBundleOnes(): void
    {
        $page = $this->client->request('GET', '/blog');

        self::assertSame('Les articles d\'Exemple sur le développement web.', $page->filter('meta[property="og:description"]')->attr('content'));
        self::assertSame('Rechercher', $page->filter('form[role=search] button')->text(), 'the labels it leaves alone come from the bundle');
    }

    private function publishedWithCover(string $title): Article
    {
        $this->client->loginUser(new Editor());
        $form = $this->client->request('GET', '/admin/blog/new')->selectButton('Enregistrer')->form([
            'blog_post[title]' => $title,
            'blog_post[content]' => 'Texte.',
        ]);
        $form['blog_post[featuredImage]']->upload($this->image(1200, 675));
        $this->client->submit($form);
        $this->client->followRedirect();
        $this->client->submit($this->client->getCrawler()->selectButton('Publier')->form());

        return $this->article();
    }

    private function article(): Article
    {
        $this->entityManager->clear();
        $articles = $this->entityManager->getRepository(Article::class)->findAll();
        self::assertCount(1, $articles);

        return $articles[0];
    }

    private function image(int $width, int $height): string
    {
        \assert($width > 0 && $height > 0);
        $path = sys_get_temp_dir().'/mulertech_blog_upload_'.uniqid().'.png';
        imagepng(imagecreatetruecolor($width, $height), $path);

        return $path;
    }
}
