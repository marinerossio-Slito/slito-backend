<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Données attendues pour POST /api/messages.
 *
 * Indiquer soit conversationId (pour poursuivre une conversation existante),
 * soit businessId (pour qu'un client en démarre une nouvelle avec une entreprise) — cf. MessagingController.
 */
class SendMessageRequest
{
    #[Assert\Positive]
    public ?int $conversationId = null;

    #[Assert\Positive]
    public ?int $businessId = null;

    #[Assert\NotBlank(message: 'Le message ne peut pas être vide.')]
    #[Assert\Length(max: 5000)]
    public ?string $content = null;

    #[Assert\Length(max: 255)]
    public ?string $attachment = null;
}
