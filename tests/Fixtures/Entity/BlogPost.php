<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Fixtures\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use MulerTech\BlogBundle\Entity\AbstractBlogPost;

/**
 * A site's entity: the shared fields, plus one of its own.
 */
#[ORM\Entity]
#[ORM\Table(name: 'blog_posts')]
class BlogPost extends AbstractBlogPost
{
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $excerpt = null;

    public function getExcerpt(): ?string
    {
        return $this->excerpt;
    }

    public function setExcerpt(?string $excerpt): static
    {
        $this->excerpt = $excerpt;

        return $this;
    }
}
