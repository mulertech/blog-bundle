<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Repository;

use MulerTech\BlogBundle\Entity\AbstractBlogPost;
use MulerTech\BlogBundle\Repository\BlogPostRepository;
use MulerTech\BlogBundle\Tests\DatabaseTestCase;
use MulerTech\BlogBundle\Tests\Fixtures\Entity\BlogPost;

final class BlogPostRepositoryTest extends DatabaseTestCase
{
    public function testAnArticleIsFoundBySlugInItsLanguageOnceItIsPublished(): void
    {
        $published = $this->post('Bienvenue', slug: 'bienvenue');
        $this->post('Welcome', 'en', slug: 'bienvenue');
        $this->post('Brouillon', publishedAt: null, slug: 'brouillon');

        self::assertSame($published, $this->repository()->findPublishedBySlug('bienvenue', 'fr'));
        self::assertNull($this->repository()->findPublishedBySlug('brouillon', 'fr'));
        self::assertNull($this->repository()->findPublishedBySlug('inconnu', 'fr'));
    }

    public function testTheListHoldsThePublishedArticlesOfOneLanguageNewestFirst(): void
    {
        $old = $this->post('Ancien', publishedAt: '2026-01-01');
        $new = $this->post('Récent', publishedAt: '2026-03-01');
        $this->post('Brouillon', publishedAt: null);
        $this->post('English', 'en');

        $page = $this->repository()->paginatePublished('fr', 1);

        self::assertSame([$new, $old], $page->items);
        self::assertSame(2, $page->totalItems);
    }

    public function testTheListIsCutIntoPages(): void
    {
        foreach (range(1, 5) as $day) {
            $this->post('Article '.$day, publishedAt: '2026-01-0'.$day);
        }

        $first = $this->repository()->paginatePublished('fr', 1, size: 2);
        $last = $this->repository()->paginatePublished('fr', 3, size: 2);

        self::assertSame(['Article 5', 'Article 4'], $this->titles($first->items));
        self::assertSame(3, $first->pageCount);
        self::assertSame(['Article 1'], $this->titles($last->items));
        self::assertFalse($last->hasNext());
    }

    /**
     * A word typed in the singular finds the plural, since it is part of it.
     */
    public function testASearchLooksInTheTitleAndTheContent(): void
    {
        $this->post('Choisir un hébergeur', content: 'Les hébergements en France.');
        $this->post('Sécuriser son site', content: 'Un hébergement mal configuré.');
        $this->post('Autre sujet', content: 'Rien à voir.');

        self::assertSame(['Sécuriser son site', 'Choisir un hébergeur'], $this->titles(
            $this->repository()->paginatePublished('fr', 1, 'hébergement')->items,
        ));
    }

    public function testASearchIgnoresCase(): void
    {
        $this->post('PostgreSQL en production', content: 'Sauvegardes.');

        self::assertCount(1, $this->repository()->paginatePublished('fr', 1, 'postgresql')->items);
        self::assertCount(1, $this->repository()->paginatePublished('fr', 1, 'SAUVEGARDES')->items);
    }

    /**
     * Every word must appear, each in the title or in the content.
     */
    public function testASearchOfSeveralWordsKeepsTheArticlesHoldingThemAll(): void
    {
        $this->post('Site vitrine', content: 'Un hébergement en France.');
        $this->post('Site vitrine bis', content: 'Rien de plus.');

        self::assertSame(['Site vitrine'], $this->titles(
            $this->repository()->paginatePublished('fr', 1, '  site   hébergement ')->items,
        ));
    }

    /**
     * An article whose title holds the words is what the visitor was most likely after.
     */
    public function testTheArticlesHoldingTheWordsInTheirTitleComeFirst(): void
    {
        $this->post('Sujet sans rapport', publishedAt: '2026-03-01', content: 'Parle de facturation électronique.');
        $this->post('Facturation électronique', publishedAt: '2026-01-01');

        self::assertSame(['Facturation électronique', 'Sujet sans rapport'], $this->titles(
            $this->repository()->paginatePublished('fr', 1, 'facturation')->items,
        ));
    }

    public function testTheWildcardsTypedByAVisitorAreLookedForLiterally(): void
    {
        $this->post('Disponible à 100%', content: 'Texte.');
        $this->post('Disponible à 1000 utilisateurs', content: 'Texte.');
        $this->post('Nom_de_variable', content: 'Texte.');
        $this->post('Nom de variable', content: 'Texte.');

        self::assertSame(['Disponible à 100%'], $this->titles($this->repository()->paginatePublished('fr', 1, '100%')->items));
        self::assertSame(['Nom_de_variable'], $this->titles($this->repository()->paginatePublished('fr', 1, 'nom_de')->items));
    }

    public function testABlankSearchListsEverything(): void
    {
        $this->post('Un');
        $this->post('Deux');

        self::assertSame(2, $this->repository()->paginatePublished('fr', 1, '   ')->totalItems);
    }

    public function testASearchOnlyCoversPublishedArticlesOfTheLanguage(): void
    {
        $this->post('Brouillon facturation', publishedAt: null);
        $this->post('Invoicing facturation', 'en');

        self::assertSame(0, $this->repository()->paginatePublished('fr', 1, 'facturation')->totalItems);
    }

    public function testTheRecentArticlesAreTheLatestPublished(): void
    {
        foreach (range(1, 6) as $day) {
            $this->post('Article '.$day, publishedAt: '2026-01-0'.$day);
        }
        $this->post('Brouillon', publishedAt: null);

        self::assertSame(['Article 6', 'Article 5', 'Article 4', 'Article 3', 'Article 2'], $this->titles($this->repository()->findRecent('fr')));
    }

    public function testThePopularArticlesAreTheMostViewed(): void
    {
        $this->setViewCount($this->post('Peu lu'), 3);
        $this->setViewCount($this->post('Très lu'), 50);
        $this->setViewCount($this->post('Assez lu'), 10);
        $this->setViewCount($this->post('Brouillon lu', publishedAt: null), 99);
        $this->entityManager->clear();

        self::assertSame(['Très lu', 'Assez lu'], $this->titles($this->repository()->findPopular('fr', 2)));
    }

    public function testPreviousAndNextFollowThePublicationDate(): void
    {
        $first = $this->post('Premier', publishedAt: '2026-01-01');
        $second = $this->post('Deuxième', publishedAt: '2026-02-01');
        $third = $this->post('Troisième', publishedAt: '2026-03-01');
        $this->post('Brouillon', publishedAt: null);
        $this->post('English', 'en', publishedAt: '2026-02-15');

        self::assertSame($first, $this->repository()->findPrevious($second));
        self::assertSame($third, $this->repository()->findNext($second));
        self::assertNull($this->repository()->findPrevious($first));
        self::assertNull($this->repository()->findNext($third));
    }

    /**
     * Articles published at the same instant, as an import produces, are still each
     * reached exactly once when moving from one to the next.
     */
    public function testArticlesSharingAPublicationDateAreAllReached(): void
    {
        $a = $this->post('A', publishedAt: '2026-01-01 10:00');
        $b = $this->post('B', publishedAt: '2026-01-01 10:00');
        $c = $this->post('C', publishedAt: '2026-01-01 10:00');

        self::assertSame($b, $this->repository()->findNext($a));
        self::assertSame($c, $this->repository()->findNext($b));
        self::assertSame($a, $this->repository()->findPrevious($b));
    }

    public function testADraftHasNoNeighbour(): void
    {
        $this->post('Publié');

        self::assertNull($this->repository()->findNext(new BlogPost()->setLocale('fr')));
    }

    public function testTheTranslationsAreThePublishedVersionsInOtherLanguages(): void
    {
        $french = $this->post('Bienvenue');
        $english = new BlogPost()->setTitle('Welcome')->setSlug('welcome')->setContent('Text.')->setLocale('en')->setTranslationOf($french)->publish(new \DateTimeImmutable());
        $german = new BlogPost()->setTitle('Willkommen')->setSlug('willkommen')->setContent('Text.')->setLocale('de')->setTranslationOf($french);
        $this->entityManager->persist($english);
        $this->entityManager->persist($german);
        $this->entityManager->flush();
        $this->post('Autre article');

        self::assertSame([$english], $this->repository()->findTranslations($french));
        self::assertSame([$french], $this->repository()->findTranslations($english));
    }

    public function testASlugIsTakenPerLanguageDraftsIncluded(): void
    {
        $draft = $this->post('Brouillon', publishedAt: null, slug: 'brouillon');

        self::assertTrue($this->repository()->isSlugTaken('brouillon', 'fr'));
        self::assertFalse($this->repository()->isSlugTaken('brouillon', 'en'));
        self::assertFalse($this->repository()->isSlugTaken('brouillon', 'fr', $draft->getId()));
    }

    private function repository(): BlogPostRepository
    {
        return new BlogPostRepository($this->entityManager, BlogPost::class);
    }

    /**
     * @param list<AbstractBlogPost> $posts
     *
     * @return list<string>
     */
    private function titles(array $posts): array
    {
        return array_map(static fn (AbstractBlogPost $post): string => $post->getTitle(), $posts);
    }
}
