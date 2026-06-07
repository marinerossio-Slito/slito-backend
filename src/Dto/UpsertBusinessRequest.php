<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Données attendues pour PUT /api/artisan/business : crée la fiche entreprise de
 * l'artisan si elle n'existe pas encore, ou remplace sa présentation sinon.
 */
class UpsertBusinessRequest
{
    #[Assert\NotBlank(message: "Le nom de l'entreprise est requis.")]
    #[Assert\Length(max: 255)]
    public ?string $name = null;

    #[Assert\NotNull(message: 'La catégorie est requise.')]
    #[Assert\Positive]
    public ?int $categoryId = null;

    #[Assert\Length(max: 255)]
    public ?string $headline = null;

    #[Assert\Length(max: 5000)]
    public ?string $description = null;

    #[Assert\Length(max: 255)]
    public ?string $coverImage = null;

    #[Assert\Length(max: 255)]
    public ?string $website = null;

    #[Assert\Type('array')]
    public ?array $paymentMethods = null;

    #[Assert\Length(max: 30)]
    public ?string $contactNumber = null;

    #[Assert\Length(max: 255)]
    public ?string $officeAddress = null;

    #[Assert\Type('array')]
    public ?array $workingHours = null;

    #[Assert\Length(max: 100)]
    public ?string $replyDelay = null;
}
