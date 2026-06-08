<?php

namespace App\Tests\Functional;

use App\Entity\Artisan;
use App\Entity\Customer;
use App\Entity\Subscription;

/**
 * Abonnement payant des artisans (étape 8 du cahier des charges), intégré à
 * Stripe (cf. SubscriptionController/SubscriptionService).
 *
 * On ne peut pas exercer ici les routes qui appellent réellement l'API Stripe
 * (checkout, portail) puisque .env utilise des identifiants fictifs
 * (`sk_test_changeme`) — ces flux sont couverts manuellement (cf. étape 8).
 * En revanche, deux choses sont entièrement testables sans réseau :
 *  - la consultation de l'abonnement courant (lecture pure, cf. AppFixtures::createSubscriptions) ;
 *  - le webhook Stripe, authentifié par une signature HMAC qu'on peut calculer
 *    nous-mêmes avec le secret de test (cf. WebhookSignature : « t=<horodatage>,v1=<hmac> »).
 */
class SubscriptionControllerTest extends ApiTestCase
{
    public function testApprovedArtisanWithASubscriptionSeesItsDetails(): void
    {
        // Cf. AppFixtures::createSubscriptions : les 5 premiers artisans approuvés
        // ont un abonnement, à différents statuts (active, trialing, past_due...).
        $artisan = $this->demoEntity(Artisan::class, ['stripeCustomerId' => $this->anyDemoStripeCustomerId()]);
        $subscription = $artisan->getSubscription();
        self::assertNotNull($subscription);

        $this->loginAs($artisan->getUser());

        $response = $this->jsonRequest('GET', '/api/artisan/subscription');

        self::assertResponseIsSuccessful();
        self::assertNotNull($response['subscription']);
        self::assertSame($subscription->getPlan(), $response['subscription']['plan']);
        self::assertSame($subscription->getStatus(), $response['subscription']['status']);
        self::assertArrayHasKey('active', $response['subscription']);
    }

    public function testApprovedArtisanWithoutASubscriptionSeesNull(): void
    {
        // Cf. AppFixtures::createSubscriptions : seuls les 5 premiers artisans
        // approuvés se voient attribuer un abonnement ; les 3 suivants n'en ont pas.
        $subscriptionFreeArtisan = null;
        foreach ($this->entityManager()->getRepository(Artisan::class)->findBy(['isApproved' => true]) as $candidate) {
            if (null === $candidate->getSubscription()) {
                $subscriptionFreeArtisan = $candidate;
                break;
            }
        }
        self::assertNotNull($subscriptionFreeArtisan, 'Le jeu de démonstration doit contenir un artisan approuvé sans abonnement.');

        $this->loginAs($subscriptionFreeArtisan->getUser());

        $response = $this->jsonRequest('GET', '/api/artisan/subscription');

        self::assertResponseIsSuccessful();
        self::assertNull($response['subscription']);
    }

    public function testCustomerCannotAccessTheArtisanSubscriptionEndpoint(): void
    {
        $customer = $this->demoEntity(Customer::class);
        $this->loginAs($customer->getUser());

        $this->client->jsonRequest('GET', '/api/artisan/subscription');

        self::assertSame(403, $this->statusCode());
    }

    // -----------------------------------------------------------------
    // Webhook Stripe (POST /api/stripe/webhook) — accès public, authentifié
    // par une signature HMAC (cf. WebhookSignature::verifyHeader)
    // -----------------------------------------------------------------

    public function testWebhookRejectsARequestWithoutASignatureHeader(): void
    {
        $payload = $this->subscriptionEventPayload('cus_demo_unused000', 'active');

        $this->client->request('POST', '/api/stripe/webhook', [], [], ['CONTENT_TYPE' => 'application/json'], $payload);

        self::assertSame(400, $this->statusCode());
        self::assertArrayHasKey('error', $this->decodeResponse());
    }

    public function testWebhookRejectsAnInvalidSignature(): void
    {
        $payload = $this->subscriptionEventPayload('cus_demo_unused000', 'active');

        $this->client->request('POST', '/api/stripe/webhook', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $this->signedStripeHeader($payload, 'whsec_un_tout_autre_secret'),
        ], $payload);

        self::assertSame(400, $this->statusCode());
        self::assertArrayHasKey('error', $this->decodeResponse());
    }

    public function testWebhookSyncsTheLocalSubscriptionFromAValidSignedEvent(): void
    {
        $artisan = $this->demoEntity(Artisan::class, ['stripeCustomerId' => $this->anyDemoStripeCustomerId()]);
        $stripeCustomerId = $artisan->getStripeCustomerId();
        self::assertNotNull($stripeCustomerId);

        $existingSubscription = $artisan->getSubscription();
        self::assertNotNull($existingSubscription, 'Cet artisan doit déjà avoir un abonnement local (cf. AppFixtures::createSubscriptions).');
        $stripeSubscriptionId = $existingSubscription->getStripeSubscriptionId();
        self::assertNotNull($stripeSubscriptionId);

        // Un événement « updated » réaliste référence le MÊME abonnement Stripe et
        // n'en change que certains attributs — ici, son statut passe à "past_due"
        // et son échéance est repoussée (cf. syncFromStripeSubscription, qui doit
        // alors mettre à jour l'abonnement existant plutôt que d'en créer un autre).
        $newPeriodEnd = (new \DateTimeImmutable('+45 days'))->setTime(12, 0);
        $payload = $this->subscriptionEventPayload(
            $stripeCustomerId,
            'past_due',
            $stripeSubscriptionId,
            $newPeriodEnd,
        );

        $this->client->request('POST', '/api/stripe/webhook', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $this->signedStripeHeader($payload),
        ], $payload);

        self::assertSame(200, $this->statusCode());
        self::assertSame(['received' => true], $this->decodeResponse());

        // L'événement doit avoir synchronisé l'abonnement local existant de cet artisan
        // (cf. SubscriptionService::syncFromStripeSubscription)
        $this->entityManager()->refresh($existingSubscription);
        self::assertSame($stripeSubscriptionId, $existingSubscription->getStripeSubscriptionId());
        self::assertSame('past_due', $existingSubscription->getStatus());
        self::assertSame('monthly', $existingSubscription->getPlan());
        self::assertSame(
            $newPeriodEnd->getTimestamp(),
            $existingSubscription->getCurrentPeriodEnd()?->getTimestamp(),
        );

        $this->entityManager()->refresh($artisan);
        self::assertSame($stripeSubscriptionId, $artisan->getSubscription()?->getStripeSubscriptionId());
    }

    public function testWebhookCreatesALocalSubscriptionWhenTheArtisanDoesNotHaveOneYet(): void
    {
        // Simule un artisan qui a déjà initié une démarche d'abonnement (il a donc
        // un Customer Stripe, cf. getOrCreateStripeCustomer) mais dont la création
        // de l'abonnement local n'a pas encore été synchronisée.
        $artisan = null;
        foreach ($this->entityManager()->getRepository(Artisan::class)->findBy(['isApproved' => true]) as $candidate) {
            if (null === $candidate->getSubscription()) {
                $artisan = $candidate;
                break;
            }
        }
        self::assertNotNull($artisan, 'Le jeu de démonstration doit contenir un artisan approuvé sans abonnement.');

        $stripeCustomerId = 'cus_test_'.bin2hex(random_bytes(6));
        $artisan->setStripeCustomerId($stripeCustomerId);
        $this->entityManager()->flush();

        $newStripeSubscriptionId = 'sub_test_'.bin2hex(random_bytes(6));
        $payload = $this->subscriptionEventPayload($stripeCustomerId, 'active', $newStripeSubscriptionId);

        $this->client->request('POST', '/api/stripe/webhook', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $this->signedStripeHeader($payload),
        ], $payload);

        self::assertSame(200, $this->statusCode());

        $subscription = $this->entityManager()->getRepository(Subscription::class)
            ->findOneBy(['stripeSubscriptionId' => $newStripeSubscriptionId]);
        self::assertNotNull($subscription, "Le webhook doit créer l'abonnement local manquant.");
        self::assertSame('active', $subscription->getStatus());

        $this->entityManager()->refresh($artisan);
        self::assertSame($newStripeSubscriptionId, $artisan->getSubscription()?->getStripeSubscriptionId());
    }

    public function testWebhookIgnoresEventsForUnknownStripeCustomers(): void
    {
        $payload = $this->subscriptionEventPayload('cus_does_not_exist_in_our_database', 'active');

        $this->client->request('POST', '/api/stripe/webhook', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $this->signedStripeHeader($payload),
        ], $payload);

        // Stripe doit toujours recevoir un accusé de réception, même pour un
        // événement qu'on choisit de ne pas appliquer (cf. applyWebhookEvent)
        self::assertSame(200, $this->statusCode());
        self::assertSame(['received' => true], $this->decodeResponse());
    }

    /**
     * Construit la charge utile JSON d'un événement Stripe `customer.subscription.updated`,
     * dans le format transmis par Stripe au webhook (cf. SubscriptionService::syncFromStripeSubscription
     * pour le détail des champs lus : customer, id, status, current_period_end, items.data[0].price).
     */
    private function subscriptionEventPayload(
        string $stripeCustomerId,
        string $status,
        ?string $stripeSubscriptionId = null,
        ?\DateTimeImmutable $currentPeriodEnd = null,
    ): string {
        $monthlyPriceId = (string) static::getContainer()->getParameter('app.stripe_price_monthly');

        return json_encode([
            'id' => 'evt_test_'.bin2hex(random_bytes(6)),
            'object' => 'event',
            'type' => 'customer.subscription.updated',
            'data' => [
                'object' => [
                    'id' => $stripeSubscriptionId ?? ('sub_test_'.bin2hex(random_bytes(6))),
                    'object' => 'subscription',
                    'customer' => $stripeCustomerId,
                    'status' => $status,
                    'current_period_end' => ($currentPeriodEnd ?? new \DateTimeImmutable('+30 days'))->getTimestamp(),
                    'items' => [
                        'object' => 'list',
                        'data' => [
                            ['price' => $monthlyPriceId],
                        ],
                    ],
                ],
            ],
        ], \JSON_THROW_ON_ERROR);
    }

    /**
     * Calcule l'en-tête Stripe-Signature attendu par WebhookSignature::verifyHeader :
     * `t=<horodatage Unix>,v1=<hex(hmac_sha256(secret, "{horodatage}.{charge utile}"))>`
     * (cf. vendor/stripe/stripe-php/lib/WebhookSignature.php).
     */
    private function signedStripeHeader(string $payload, ?string $secret = null): string
    {
        $secret ??= (string) static::getContainer()->getParameter('app.stripe_webhook_secret');
        $timestamp = time();
        $signature = hash_hmac('sha256', \sprintf('%d.%s', $timestamp, $payload), $secret);

        return \sprintf('t=%d,v1=%s', $timestamp, $signature);
    }

    /**
     * Renvoie l'identifiant Customer Stripe d'un artisan abonné du jeu de
     * démonstration (cf. AppFixtures::createSubscriptions, qui en attribue un
     * aux 5 premiers artisans approuvés). On ne peut pas exprimer « IS NOT NULL »
     * via findOneBy, d'où ce petit détour par une requête dédiée.
     */
    private function anyDemoStripeCustomerId(): string
    {
        $artisan = $this->entityManager()->getRepository(Artisan::class)
            ->createQueryBuilder('a')
            ->where('a.stripeCustomerId IS NOT NULL')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        self::assertInstanceOf(Artisan::class, $artisan, "Le jeu de démonstration doit contenir un artisan avec un identifiant Customer Stripe (cf. AppFixtures::createSubscriptions).");

        $stripeCustomerId = $artisan->getStripeCustomerId();
        self::assertNotNull($stripeCustomerId);

        return $stripeCustomerId;
    }
}
