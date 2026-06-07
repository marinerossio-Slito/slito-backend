<?php

namespace App\Service;

use App\Entity\Artisan;
use App\Entity\Subscription;
use App\Repository\ArtisanRepository;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Event;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * Encapsule l'intégration Stripe pour les abonnements payants des artisans (étape 8
 * du cahier des charges, cf. ARCHITECTURE.md « Paiement (recommandation) »).
 *
 * Règle de sécurité impérative : aucune donnée bancaire ne transite par notre
 * serveur. La saisie des moyens de paiement est entièrement déléguée aux pages
 * hébergées par Stripe (Checkout Session pour souscrire, Customer Portal pour
 * gérer/résilier) ; nous ne stockons et manipulons que des identifiants Stripe
 * opaques (Customer id, Subscription id) ainsi qu'un statut et une échéance,
 * synchronisés via le webhook Stripe.
 */
class SubscriptionService
{
    public const PLAN_MONTHLY = 'monthly';
    public const PLAN_YEARLY = 'yearly';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ArtisanRepository $artisanRepository,
        private readonly string $stripeSecretKey,
        private readonly string $stripeWebhookSecret,
        private readonly string $stripePriceMonthly,
        private readonly string $stripePriceYearly,
        private readonly string $stripeCheckoutSuccessUrl,
        private readonly string $stripeCheckoutCancelUrl,
    ) {
    }

    private function client(): StripeClient
    {
        return new StripeClient($this->stripeSecretKey);
    }

    /**
     * @return array<string, string> formule => identifiant du Price Stripe
     */
    private function priceIdsByPlan(): array
    {
        return [
            self::PLAN_MONTHLY => $this->stripePriceMonthly,
            self::PLAN_YEARLY => $this->stripePriceYearly,
        ];
    }

    public function isSupportedPlan(string $plan): bool
    {
        return \array_key_exists($plan, $this->priceIdsByPlan());
    }

    /**
     * Renvoie l'identifiant du Customer Stripe de l'artisan, en le créant au besoin.
     *
     * Note de conception : l'architecture suggère de créer le Customer dès
     * l'inscription de l'artisan ; nous le faisons ici paresseusement, à la première
     * démarche d'abonnement, pour ne pas coupler un chemin critique (création de
     * compte) à la disponibilité du réseau/de l'API Stripe. Le résultat est
     * identique : tout artisan qui entame un abonnement dispose d'un Customer.
     *
     * @throws \Stripe\Exception\ApiErrorException
     */
    public function getOrCreateStripeCustomer(Artisan $artisan): string
    {
        $existing = $artisan->getStripeCustomerId();
        if (null !== $existing) {
            return $existing;
        }

        $user = $artisan->getUser();
        $customer = $this->client()->customers->create([
            'email' => $user?->getEmail(),
            'name' => trim(sprintf('%s %s', $user?->getFirstName(), $user?->getLastName())),
            'metadata' => ['artisanId' => (string) $artisan->getId()],
        ]);

        $artisan->setStripeCustomerId($customer->id);
        $this->entityManager->flush();

        return $customer->id;
    }

    /**
     * Crée une session Stripe Checkout en mode abonnement et renvoie son URL : le
     * client doit être redirigé vers cette page hébergée par Stripe pour saisir ses
     * informations de paiement (jamais sur notre serveur, cf. note de classe).
     *
     * @throws \InvalidArgumentException si la formule est inconnue
     * @throws \Stripe\Exception\ApiErrorException en cas d'échec de l'appel Stripe
     */
    public function createCheckoutSessionUrl(Artisan $artisan, string $plan): string
    {
        $priceId = $this->priceIdsByPlan()[$plan] ?? null;
        if (null === $priceId) {
            throw new \InvalidArgumentException(sprintf('Formule d\'abonnement inconnue : "%s".', $plan));
        }

        $customerId = $this->getOrCreateStripeCustomer($artisan);

        $session = $this->client()->checkout->sessions->create([
            'mode' => 'subscription',
            'customer' => $customerId,
            'line_items' => [['price' => $priceId, 'quantity' => 1]],
            'success_url' => $this->stripeCheckoutSuccessUrl,
            'cancel_url' => $this->stripeCheckoutCancelUrl,
            'client_reference_id' => (string) $artisan->getId(),
            'metadata' => ['artisanId' => (string) $artisan->getId(), 'plan' => $plan],
        ]);

        if (null === $session->url) {
            throw new \RuntimeException("Stripe n'a pas renvoyé d'URL de paiement.");
        }

        return $session->url;
    }

    /**
     * Crée une session du Customer Portal Stripe : l'artisan y gère lui-même son
     * moyen de paiement, change de formule ou résilie, sans aucune interface à
     * développer côté Slito.
     *
     * @throws \RuntimeException si l'artisan n'a pas encore de Customer Stripe
     * @throws \Stripe\Exception\ApiErrorException en cas d'échec de l'appel Stripe
     */
    public function createBillingPortalSessionUrl(Artisan $artisan): string
    {
        $customerId = $artisan->getStripeCustomerId();
        if (null === $customerId) {
            throw new \RuntimeException("Aucun abonnement n'a encore été initié pour ce compte.");
        }

        $session = $this->client()->billingPortal->sessions->create([
            'customer' => $customerId,
            'return_url' => $this->stripeCheckoutSuccessUrl,
        ]);

        if (null === $session->url) {
            throw new \RuntimeException("Stripe n'a pas renvoyé d'URL vers l'espace de gestion de l'abonnement.");
        }

        return $session->url;
    }

    /**
     * Vérifie la signature et reconstruit l'événement à partir du corps brut de la
     * requête webhook (cf. POST /api/stripe/webhook). Lève SignatureVerificationException
     * si la signature est invalide — le contrôleur traduit cela en 400.
     *
     * @throws \Stripe\Exception\SignatureVerificationException
     * @throws \UnexpectedValueException si le corps n'est pas un JSON d'événement valide
     */
    public function constructWebhookEvent(string $payload, string $signatureHeader): Event
    {
        return Webhook::constructEvent($payload, $signatureHeader, $this->stripeWebhookSecret);
    }

    /**
     * Synchronise l'abonnement local à partir d'un événement Stripe reçu par le
     * webhook. Les types d'événements non liés au cycle de vie de l'abonnement sont
     * ignorés (acquittés sans effet) : Stripe ne doit jamais recevoir d'erreur pour
     * un événement qu'on choisit délibérément de ne pas traiter.
     */
    public function applyWebhookEvent(Event $event): void
    {
        $object = $event->data->object ?? null;
        if (null === $object) {
            return;
        }

        match ($event->type) {
            'customer.subscription.created', 'customer.subscription.updated' => $this->syncFromStripeSubscription($object, false),
            'customer.subscription.deleted' => $this->syncFromStripeSubscription($object, true),
            default => null,
        };
    }

    /**
     * Crée ou met à jour l'entité Subscription locale d'après un objet Subscription
     * Stripe (transmis intégralement dans `data.object` par les trois événements
     * `customer.subscription.*` traités). La correspondance avec l'artisan se fait
     * via stripeCustomerId, renseigné lors de la création du Customer.
     */
    private function syncFromStripeSubscription(object $stripeSubscription, bool $deleted): void
    {
        $customer = $stripeSubscription->customer ?? null;
        $customerId = \is_string($customer) ? $customer : ($customer->id ?? null);
        if (null === $customerId) {
            return;
        }

        $artisan = $this->artisanRepository->findOneBy(['stripeCustomerId' => $customerId]);
        if (null === $artisan) {
            // Webhook reçu pour un Customer Stripe qui ne correspond à aucun artisan
            // connu (compte supprimé entre-temps, par exemple) : rien à synchroniser.
            return;
        }

        $subscriptionId = $stripeSubscription->id ?? null;
        if (!\is_string($subscriptionId)) {
            return;
        }

        $subscription = $artisan->getSubscription();
        if (null === $subscription || $subscription->getStripeSubscriptionId() !== $subscriptionId) {
            $subscription = $this->entityManager->getRepository(Subscription::class)
                ->findOneBy(['stripeSubscriptionId' => $subscriptionId]) ?? new Subscription();
        }

        $status = $deleted ? 'canceled' : (string) ($stripeSubscription->status ?? 'unknown');
        $periodEndTimestamp = $stripeSubscription->current_period_end ?? null;

        $subscription
            ->setStripeSubscriptionId($subscriptionId)
            ->setStatus($status)
            ->setPlan($this->planFromStripePriceId($this->extractPriceId($stripeSubscription)))
            ->setCurrentPeriodEnd(\is_int($periodEndTimestamp) ? (new \DateTimeImmutable())->setTimestamp($periodEndTimestamp) : null);

        $artisan->setSubscription($subscription);

        $this->entityManager->persist($subscription);
        $this->entityManager->flush();
    }

    private function extractPriceId(object $stripeSubscription): ?string
    {
        $items = $stripeSubscription->items->data ?? null;
        if (!\is_array($items) || !isset($items[0])) {
            return null;
        }

        $price = $items[0]->price ?? null;

        return \is_string($price) ? $price : ($price->id ?? null);
    }

    private function planFromStripePriceId(?string $priceId): string
    {
        return match ($priceId) {
            $this->stripePriceMonthly => self::PLAN_MONTHLY,
            $this->stripePriceYearly => self::PLAN_YEARLY,
            default => 'unknown',
        };
    }
}
