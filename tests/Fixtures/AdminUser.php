<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Fixtures;

use MulerTech\FileBundle\Model\FileUploaderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class AdminUser implements UserInterface, FileUploaderInterface
{
    public function getId(): int
    {
        return 7;
    }

    public function getRoles(): array
    {
        return ['ROLE_ADMIN'];
    }

    public function getUserIdentifier(): string
    {
        return 'admin@example.com';
    }
}
