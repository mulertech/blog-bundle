<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Entity;

use MulerTech\BlogBundle\Tests\Fixtures\Entity\BlogCategory;
use PHPUnit\Framework\TestCase;

final class AbstractBlogTermTest extends TestCase
{
    public function testTheVersionsOfATermShareItsGroup(): void
    {
        $french = new BlogCategory()->setName('Hébergement')->setLocale('fr');
        $english = new BlogCategory()->setName('Hosting')->setLocale('en');
        self::assertNotSame($french->getTranslationGroup(), $english->getTranslationGroup());

        $english->setTranslationOf($french);

        self::assertSame($french->getTranslationGroup(), $english->getTranslationGroup());
    }
}
