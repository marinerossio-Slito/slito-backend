<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Données attendues pour PATCH /api/admin/users/{id}.
 *
 * Deux leviers de gestion (cf. AdminController), fournis indépendamment ou ensemble :
 *  - isBanned : suspendre / réactiver un compte ;
 *  - isApproved : valider / révoquer le justificatif d'un artisan (déclenche une notification).
 */
class AdminUpdateUserRequest
{
    #[Assert\Type('bool')]
    public ?bool $isBanned = null;

    #[Assert\Type('bool')]
    public ?bool $isApproved = null;
}
