<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A tag of the blog: an article has any number of them. A site extends it with its own
 * entity, named by `mulertech_blog.tag_class`, which owns the table.
 */
#[ORM\MappedSuperclass]
abstract class AbstractBlogTag extends AbstractBlogTerm
{
}
