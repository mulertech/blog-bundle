<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use MulerTech\BlogBundle\Entity\AbstractBlogPost;

#[ORM\Entity]
#[ORM\Table(name: 'blog_posts')]
class BlogPost extends AbstractBlogPost
{
}
