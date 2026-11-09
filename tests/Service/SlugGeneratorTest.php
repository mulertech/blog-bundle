<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Service;

use MulerTech\BlogBundle\Repository\BlogPostRepository;
use MulerTech\BlogBundle\Service\SlugGenerator;
use MulerTech\BlogBundle\Tests\DatabaseTestCase;
use MulerTech\BlogBundle\Tests\Fixtures\Entity\BlogPost;

final class SlugGeneratorTest extends DatabaseTestCase
{
    public function testTheSlugIsTheTitleInLowercaseAsciiWords(): void
    {
        self::assertSame('heberger-son-site-en-france-l-essentiel', $this->generator()->generate($this->draft('Héberger son site en France : l’essentiel !')));
    }

    public function testATitleAlreadyUsedInTheLanguageGetsANumber(): void
    {
        $this->post('Bienvenue', slug: 'bienvenue');
        $this->post('Bienvenue', slug: 'bienvenue-2');

        self::assertSame('bienvenue-3', $this->generator()->generate($this->draft('Bienvenue')));
        self::assertSame('bienvenue', $this->generator()->generate($this->draft('Bienvenue', 'en')));
    }

    /**
     * Saving an article again must not move it to a numbered slug of its own.
     */
    public function testAnArticleKeepsItsOwnSlug(): void
    {
        $post = $this->post('Bienvenue', slug: 'bienvenue');

        self::assertSame('bienvenue', $this->generator()->generate($post));
    }

    public function testAVeryLongTitleLeavesRoomForTheNumber(): void
    {
        $slug = $this->generator()->generate($this->draft(str_repeat('mot ', 100)));

        self::assertLessThanOrEqual(240, \strlen($slug));
        self::assertStringEndsNotWith('-', $slug);
    }

    public function testATitleWithoutLetterNorDigitIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The title "?!" yields no slug');

        $this->generator()->generate($this->draft('?!'));
    }

    private function draft(string $title, string $locale = 'fr'): BlogPost
    {
        return new BlogPost()->setTitle($title)->setLocale($locale);
    }

    private function generator(): SlugGenerator
    {
        return new SlugGenerator(new BlogPostRepository($this->entityManager, BlogPost::class, $this->clock));
    }
}
