<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Functional\App\Security;

use MulerTech\FileBundle\Model\FileUploaderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class Editor implements UserInterface, FileUploaderInterface
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        private readonly array $roles = ['ROLE_ADMIN'],
    ) {
    }

    public function getId(): int
    {
        return 1;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function getUserIdentifier(): string
    {
        return 'editor';
    }
}
