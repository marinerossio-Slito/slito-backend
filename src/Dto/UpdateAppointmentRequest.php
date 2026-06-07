<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Données attendues pour PATCH /api/appointments/{id}.
 *
 * Deux usages mutuellement exclusifs (cf. AppointmentController) :
 *  - changement de statut (status) : accepter / refuser / annuler / terminer, selon le rôle ;
 *  - modification des détails (dateTime, location, customerNote), réservée au client
 *    tant que la demande est encore PENDING.
 */
class UpdateAppointmentRequest
{
    #[Assert\Choice(
        choices: ['PENDING', 'CONFIRMED', 'CANCELLED', 'COMPLETED'],
        message: 'Le statut doit être PENDING, CONFIRMED, CANCELLED ou COMPLETED.',
    )]
    public ?string $status = null;

    #[Assert\Type('string')]
    public ?string $dateTime = null;

    #[Assert\Choice(choices: ['HOME', 'WORKSHOP'], message: 'La localisation doit être HOME ou WORKSHOP.')]
    public ?string $location = null;

    #[Assert\Length(max: 2000)]
    public ?string $customerNote = null;
}
