<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use MulerTech\BlogBundle\Entity\AbstractBlogCategory;

/**
 * A site's category: the shared fields, plus one of its own.
 */
#[ORM\Entity]
#[ORM\Table(name: 'blog_categories')]
class BlogCategory extends AbstractBlogCategory
{
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $color = null;

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): static
    {
        $this->color = $color;

        return $this;
    }
}
