<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Empêche la connexion des comptes bannis par un administrateur (cf. AdminController,
 * PATCH /api/admin/users/{id}). Vérifié à chaque authentification (login JSON et JWT).
 */
class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof User && $user->isBanned()) {
            throw new CustomUserMessageAccountStatusException('Ce compte a été suspendu par un administrateur.');
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
    }
}
