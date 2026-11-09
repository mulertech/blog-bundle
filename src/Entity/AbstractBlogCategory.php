<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A category of the blog: an article has at most one. A site extends it with its own
 * entity, named by `mulertech_blog.category_class`, which owns the table.
 */
#[ORM\MappedSuperclass]
abstract class AbstractBlogCategory extends AbstractBlogTerm
{
    /**
     * Plain text, shown above the articles of the category.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $description = null;

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }
}
