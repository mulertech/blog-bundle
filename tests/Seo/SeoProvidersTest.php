<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Seo;

use MulerTech\BlogBundle\Repository\BlogPostRepository;
use MulerTech\BlogBundle\Seo\BlogPostLlmsSectionProvider;
use MulerTech\BlogBundle\Seo\BlogPostSitemapProvider;
use MulerTech\BlogBundle\Seo\BlogPostSocialCardProvider;
use MulerTech\BlogBundle\Service\PostUrlGenerator;
use MulerTech\BlogBundle\Tests\DatabaseTestCase;
use MulerTech\BlogBundle\Tests\Fixtures\Entity\BlogPost;
use MulerTech\BlogBundle\Tests\Fixtures\Entity\File;
use MulerTech\FileBundle\Model\FileInterface;
use MulerTech\FileBundle\Service\FileManagerInterface;
use MulerTech\SeoBundle\Model\LlmsLink;
use MulerTech\SeoBundle\Model\SitemapUrl;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Loader\YamlFileLoader;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Translation\Loader\YamlFileLoader as TranslationYamlLoader;
use Symfony\Component\Translation\Translator;

final class SeoProvidersTest extends DatabaseTestCase
{
    public function testTheSitemapListsEachLanguageAndEveryVersionOfEveryArticle(): void
    {
        $french = $this->post('Bienvenue', publishedAt: '2026-03-14 10:00', slug: 'bienvenue');
        $english = new BlogPost()->setTitle('Welcome')->setSlug('welcome')->setContent('Text.')->setLocale('en')->setTranslationOf($french)->publish(new \DateTimeImmutable('2026-03-15 10:00'));
        $this->entityManager->persist($english);
        $this->entityManager->flush();
        $this->post('Brouillon', publishedAt: null);

        $urls = [...new BlogPostSitemapProvider($this->repository(), $this->urls())->getUrls()];

        self::assertSame([
            'https://example.com/fr/blog',
            'https://example.com/en/blog',
            'https://example.com/en/blog/welcome',
            'https://example.com/fr/blog/bienvenue',
        ], array_map(static fn (SitemapUrl $url): string => $url->getLoc(), $urls));
        self::assertSame('2026-03-14T10:00:00+00:00', $urls[3]->getLastmod());
    }

    /**
     * A corrected article tells search engines it changed since it was published.
     */
    public function testTheSitemapDatesAnArticleByItsLastChange(): void
    {
        $post = $this->post('Bienvenue', publishedAt: '2026-03-14 10:00');
        $post->setTitle('Bienvenue à tous');
        $this->entityManager->flush();

        $urls = [...new BlogPostSitemapProvider($this->repository(), $this->urls())->getUrls()];

        self::assertSame($post->getSeoUpdatedAt(), $urls[2]->getLastmod());
        self::assertNotNull($urls[2]->getLastmod());
    }

    public function testLlmsTxtListsThePublishedArticlesUnderTheBlogHeading(): void
    {
        $this->post('Bienvenue', slug: 'bienvenue')->setMetaDescription('Pour commencer.');
        $this->entityManager->flush();
        $this->post('Brouillon', publishedAt: null);

        $sections = [...$this->llms()->getSections()];

        self::assertCount(1, $sections);
        self::assertSame('Blog', $sections[0]->getHeading());
        self::assertEquals([new LlmsLink('https://example.com/fr/blog/bienvenue', 'Bienvenue', 'Pour commencer.')], $sections[0]->getLinks());
    }

    /**
     * An empty heading would announce a blog with nothing in it.
     */
    public function testLlmsTxtHasNoBlogSectionWithoutArticle(): void
    {
        self::assertSame([], [...$this->llms()->getSections()]);
    }

    public function testTheSocialCardIsDerivedFromTheFeaturedImageOfAPublishedArticle(): void
    {
        $post = $this->post('Illustré');
        $cover = $this->cover('cover-abc.webp');
        $post->setFeaturedImage($cover);
        $this->entityManager->flush();

        $source = $this->socialCards()->findSocialCardSource((string) $post->getId());

        self::assertNotNull($source);
        self::assertSame('/storage/cover-abc.webp', $source->path);
        self::assertSame('cover-abc.webp|1024', $source->version);
        self::assertSame('blog', $this->socialCards()->getSocialCardType());
    }

    /**
     * The stored name is unique to each upload: a replaced image gets a new address, which
     * is what makes platforms show it rather than their cached preview.
     */
    public function testReplacingTheImageChangesTheVersion(): void
    {
        self::assertNotSame(
            $this->socialCards()->source($this->cover('cover-abc.webp'))->version,
            $this->socialCards()->source($this->cover('cover-def.webp'))->version,
        );
    }

    public function testADraftOrAnArticleWithoutImageHasNoCard(): void
    {
        $draft = $this->post('Brouillon', publishedAt: null);
        $draft->setFeaturedImage($this->cover('cover.webp'));
        $plain = $this->post('Sans image');
        $this->entityManager->flush();

        self::assertNull($this->socialCards()->findSocialCardSource((string) $draft->getId()));
        self::assertNull($this->socialCards()->findSocialCardSource((string) $plain->getId()));
        self::assertNull($this->socialCards()->findSocialCardSource('999'));
        self::assertNull($this->socialCards()->findSocialCardSource('12-ab'));
    }

    /**
     * A scheduled article stays out of search engines, `llms.txt` and the social platforms
     * until its date, then enters them without anything to run.
     */
    public function testAScheduledArticleIsDeclaredOnceItsDateIsReached(): void
    {
        $post = $this->post('À venir', publishedAt: '2026-07-14 08:30', slug: 'a-venir');
        $post->setFeaturedImage($this->cover('cover.webp'));
        $this->entityManager->flush();
        $sitemap = new BlogPostSitemapProvider($this->repository(), $this->urls());

        self::assertCount(2, [...$sitemap->getUrls()]);
        self::assertSame([], [...$this->llms()->getSections()]);
        self::assertNull($this->socialCards()->findSocialCardSource((string) $post->getId()));

        $this->clock->modify('2026-07-14 08:30');

        self::assertSame('https://example.com/fr/blog/a-venir', [...$sitemap->getUrls()][2]->getLoc());
        self::assertCount(1, [...$this->llms()->getSections()]);
        self::assertNotNull($this->socialCards()->findSocialCardSource((string) $post->getId()));
    }

    private function repository(): BlogPostRepository
    {
        return new BlogPostRepository($this->entityManager, BlogPost::class, $this->clock);
    }

    private function urls(): PostUrlGenerator
    {
        $routes = new YamlFileLoader(new FileLocator(\dirname(__DIR__).'/Fixtures/config'))->load('routes.yaml');

        return new PostUrlGenerator(new UrlGenerator($routes, new RequestContext(host: 'example.com', scheme: 'https')), ['fr', 'en']);
    }

    private function llms(): BlogPostLlmsSectionProvider
    {
        $translator = new Translator('fr');
        $translator->addLoader('yaml', new TranslationYamlLoader());
        $translator->addResource('yaml', \dirname(__DIR__, 2).'/translations/MulerTechBlogBundle.fr.yaml', 'fr', 'MulerTechBlogBundle');

        return new BlogPostLlmsSectionProvider($this->repository(), $this->urls(), $translator);
    }

    private function socialCards(): BlogPostSocialCardProvider
    {
        $fileManager = $this->createStub(FileManagerInterface::class);
        $fileManager->method('getAbsolutePath')->willReturnCallback(static fn (FileInterface $file): string => '/storage/'.$file->getStoredFilename());

        return new BlogPostSocialCardProvider($this->repository(), $fileManager, $this->clock);
    }

    private function cover(string $storedFilename): File
    {
        $file = new File()->setFilename('cover.webp')->setStoredFilename($storedFilename)->setFilepath($storedFilename)->setMimeType('image/webp')->setSize('1024')->setExtension('webp');
        $this->entityManager->persist($file);

        return $file;
    }
}
