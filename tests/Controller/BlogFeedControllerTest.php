<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Controller;

use MulerTech\BlogBundle\Controller\BlogFeedController;
use Symfony\Component\HttpFoundation\Response;

final class BlogFeedControllerTest extends PublicPageTestCase
{
    public function testTheFeedListsTheLatestArticlesOfTheLanguage(): void
    {
        $this->post('Héberger un site', publishedAt: '2026-03-14 10:00', slug: 'heberger-un-site')->setExcerpt('Pourquoi un hébergeur en France.');
        $this->post('Bienvenue', publishedAt: '2026-01-01 10:00', slug: 'bienvenue');
        $this->post('Welcome', locale: 'en', slug: 'welcome');
        $this->post('Brouillon', publishedAt: null);
        $this->post('À venir', publishedAt: '2026-07-14 08:30');
        $this->entityManager->flush();

        $response = $this->feed();
        $feed = $this->xml($response);

        self::assertSame('application/atom+xml; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertSame('Blog', (string) $feed->title);
        self::assertSame('https://example.com/fr/blog', (string) $feed->id);
        self::assertSame('https://example.com/fr/blog/feed.xml', (string) $feed->link[0]['href']);
        self::assertSame(['Héberger un site', 'Bienvenue'], array_map(static fn (\SimpleXMLElement $entry): string => (string) $entry->title, iterator_to_array($feed->entry, false)));

        $entry = $feed->entry[0];
        self::assertSame('https://example.com/fr/blog/heberger-un-site', (string) $entry->id);
        self::assertSame('2026-03-14T10:00:00+00:00', (string) $entry->published);
        self::assertSame('Jeanne Martin', (string) $entry->author->name);
        self::assertSame('Pourquoi un hébergeur en France.', (string) $entry->summary);
        self::assertCount(0, $feed->entry[1]->summary);
        self::assertSame((string) $entry->updated, (string) $feed->updated);
    }

    public function testAFeedWithoutArticleIsDatedNow(): void
    {
        $feed = $this->xml($this->feed());

        self::assertCount(0, $feed->entry);
        self::assertSame('2026-07-01T12:00:00+00:00', (string) $feed->updated);
    }

    public function testATitleWithMarkupStaysText(): void
    {
        $this->post('<b>Gras</b> & co', slug: 'gras');

        self::assertSame('<b>Gras</b> & co', (string) $this->xml($this->feed())->entry[0]->title);
    }

    private function feed(): Response
    {
        return new BlogFeedController($this->repository(), $this->postUrls(), $this->twig, $this->clock)($this->request(path: '/fr/blog/feed.xml'));
    }

    private function xml(Response $response): \SimpleXMLElement
    {
        $feed = simplexml_load_string((string) $response->getContent());
        self::assertInstanceOf(\SimpleXMLElement::class, $feed);

        return $feed;
    }
}
