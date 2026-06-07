<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Données attendues pour POST /api/password/reset/{token} (saisie du nouveau mot de passe).
 */
class PasswordResetConfirmation
{
    #[Assert\NotBlank]
    #[Assert\Length(min: 8, max: 4096)]
    public ?string $password = null;
}
