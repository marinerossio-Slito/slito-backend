<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Données attendues pour POST /api/artisan/subscription/checkout : la formule
 * d'abonnement choisie, qui détermine le Price Stripe utilisé pour la session
 * Checkout (cf. SubscriptionService).
 */
class SubscriptionCheckoutRequest
{
    #[Assert\NotBlank(message: 'La formule d\'abonnement est obligatoire.')]
    #[Assert\Choice(
        choices: ['monthly', 'yearly'],
        message: 'La formule doit être "monthly" (mensuelle) ou "yearly" (annuelle).',
    )]
    public ?string $plan = null;
}
