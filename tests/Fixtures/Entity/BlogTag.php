<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use MulerTech\BlogBundle\Entity\AbstractBlogTag;

#[ORM\Entity]
#[ORM\Table(name: 'blog_tags')]
class BlogTag extends AbstractBlogTag
{
}
