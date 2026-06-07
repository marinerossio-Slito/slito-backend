<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Données attendues pour POST /api/admin/categories.
 *
 * Le slug est optionnel : s'il n'est pas fourni, il est dérivé du nom (cf. AdminController::slugify).
 */
class CreateCategoryRequest
{
    #[Assert\NotBlank(message: 'Le nom de la catégorie est requis.')]
    #[Assert\Length(max: 100)]
    public ?string $name = null;

    #[Assert\Length(max: 100)]
    public ?string $icon = null;

    #[Assert\Length(max: 120)]
    #[Assert\Regex(
        pattern: '/^[a-z0-9]+(-[a-z0-9]+)*$/',
        message: 'Le slug doit être en minuscules et ne contenir que des lettres, chiffres et tirets.',
    )]
    public ?string $slug = null;
}
