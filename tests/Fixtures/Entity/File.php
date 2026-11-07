<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use MulerTech\FileBundle\Entity\AbstractFile;

#[ORM\Entity]
#[ORM\Table(name: 'files')]
class File extends AbstractFile
{
}
