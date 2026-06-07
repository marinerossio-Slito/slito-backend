<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Données attendues pour POST /api/reviews.
 *
 * L'avis est bidirectionnel (cf. ReviewController) : selon que l'auteur est le client
 * ou l'artisan du rendez-vous, authorType et target sont déduits automatiquement.
 */
class CreateReviewRequest
{
    #[Assert\NotNull(message: "L'identifiant du rendez-vous est requis.")]
    #[Assert\Positive]
    public ?int $appointmentId = null;

    #[Assert\NotNull(message: 'La note globale est requise.')]
    #[Assert\Range(notInRangeMessage: 'La note doit être comprise entre {{ min }} et {{ max }}.', min: 1, max: 5)]
    public ?int $rating = null;

    #[Assert\Range(notInRangeMessage: 'La note de ponctualité doit être comprise entre {{ min }} et {{ max }}.', min: 1, max: 5)]
    public ?int $punctualityRating = null;

    #[Assert\Range(notInRangeMessage: 'La note de qualité doit être comprise entre {{ min }} et {{ max }}.', min: 1, max: 5)]
    public ?int $qualityRating = null;

    #[Assert\Length(max: 2000)]
    public ?string $comment = null;
}
