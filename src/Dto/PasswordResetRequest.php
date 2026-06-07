<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Données attendues pour POST /api/password/reset (demande de lien de réinitialisation).
 */
class PasswordResetRequest
{
    #[Assert\NotBlank]
    #[Assert\Email]
    public ?string $email = null;
}
