<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Functional\App\Entity;

use Doctrine\ORM\Mapping as ORM;
use MulerTech\BlogBundle\Entity\AbstractBlogPost;

#[ORM\Entity]
#[ORM\Table(name: 'blog_posts')]
class Article extends AbstractBlogPost
{
    public function getSeoAuthorName(): string
    {
        return 'Exemple';
    }
}
