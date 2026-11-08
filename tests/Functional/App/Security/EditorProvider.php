<?php

declare(strict_types=1);

namespace MulerTech\BlogBundle\Tests\Functional\App\Security;

use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * @implements UserProviderInterface<Editor>
 */
final class EditorProvider implements UserProviderInterface
{
    public function refreshUser(UserInterface $user): UserInterface
    {
        return new Editor($user->getRoles());
    }

    public function supportsClass(string $class): bool
    {
        return Editor::class === $class;
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        return new Editor();
    }
}
