<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Model;

use MulerTech\BlogBundle\Entity\AbstractBlogPost;

/**
 * One page of a list of articles.
 */
final readonly class Page
{
    public int $pageCount;

    /**
     * @param list<AbstractBlogPost> $items
     * @param positive-int           $number starting at 1
     * @param positive-int           $size
     */
    public function __construct(
        public array $items,
        public int $number,
        public int $size,
        public int $totalItems,
    ) {
        $this->pageCount = max(1, (int) ceil($totalItems / $size));
    }

    public function hasPrevious(): bool
    {
        return $this->number > 1;
    }

    public function hasNext(): bool
    {
        return $this->number < $this->pageCount;
    }

    /**
     * Beyond the last page there is nothing to show: the address of such a page is one
     * that answers 404 rather than an empty list.
     */
    public function exists(): bool
    {
        return $this->number <= $this->pageCount;
    }
}
