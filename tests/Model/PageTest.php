<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Model;

use MulerTech\BlogBundle\Model\Page;
use PHPUnit\Framework\TestCase;

final class PageTest extends TestCase
{
    public function testTheNumberOfPagesCoversEveryItem(): void
    {
        self::assertSame(3, new Page([], 1, 12, 25)->pageCount);
        self::assertSame(2, new Page([], 1, 12, 24)->pageCount);
    }

    /**
     * An empty blog still has its list page, empty.
     */
    public function testAnEmptyListIsOnePage(): void
    {
        $page = new Page([], 1, 12, 0);

        self::assertSame(1, $page->pageCount);
        self::assertTrue($page->exists());
        self::assertFalse($page->hasPrevious());
        self::assertFalse($page->hasNext());
    }

    public function testAMiddlePageLeadsBothWays(): void
    {
        $page = new Page([], 2, 12, 30);

        self::assertTrue($page->hasPrevious());
        self::assertTrue($page->hasNext());
    }

    public function testAPageBeyondTheLastDoesNotExist(): void
    {
        self::assertFalse(new Page([], 4, 12, 30)->exists());
    }
}
