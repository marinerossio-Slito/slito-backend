<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Données attendues pour POST /api/register/artisan.
 *
 * Règle métier : le compte est créé avec isApproved = false ; il devra être
 * validé par un admin (justificatif certifié) avant activation du dashboard.
 */
class RegisterArtisanRequest
{
    #[Assert\NotBlank]
    #[Assert\Email]
    public ?string $email = null;

    #[Assert\NotBlank]
    #[Assert\Length(min: 8, max: 4096)]
    public ?string $password = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public ?string $firstName = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public ?string $lastName = null;

    #[Assert\Length(max: 30)]
    public ?string $phone = null;

    #[Assert\NotBlank]
    #[Assert\Length(min: 14, max: 14)]
    #[Assert\Regex(pattern: '/^\d{14}$/', message: 'Le SIRET doit contenir 14 chiffres.')]
    public ?string $siret = null;

    #[Assert\Length(max: 255)]
    public ?string $officeAddress = null;

    /**
     * Référence du justificatif de propriété de l'entreprise déjà téléversé
     * (ex : via un endpoint d'upload dédié à brancher ultérieurement).
     */
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public ?string $ownershipDocument = null;
}
