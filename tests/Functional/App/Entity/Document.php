<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Functional\App\Entity;

use Doctrine\ORM\Mapping as ORM;
use MulerTech\FileBundle\Entity\AbstractFile;

#[ORM\Entity]
#[ORM\Table(name: 'documents')]
class Document extends AbstractFile
{
}
