<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Données attendues pour POST /api/appointments (demande de rendez-vous, status PENDING).
 */
class CreateAppointmentRequest
{
    #[Assert\NotNull(message: "L'identifiant de la prestation est requis.")]
    #[Assert\Positive]
    public ?int $serviceId = null;

    #[Assert\NotBlank(message: 'La date et l\'heure du rendez-vous sont requises (format ISO 8601, ex : 2026-07-01T14:30:00+02:00).')]
    public ?string $dateTime = null;

    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['HOME', 'WORKSHOP'], message: 'La localisation doit être HOME (domicile du client) ou WORKSHOP (atelier de l\'artisan).')]
    public ?string $location = null;

    #[Assert\Length(max: 2000)]
    public ?string $customerNote = null;
}
