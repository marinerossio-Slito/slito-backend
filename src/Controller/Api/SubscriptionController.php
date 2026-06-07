<?php

namespace App\Controller\Api;

use App\Dto\SubscriptionCheckoutRequest;
use App\Entity\Artisan;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\SubscriptionService;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Abonnement payant des artisans (étape 8 du cahier des charges), géré via Stripe.
 *
 * Deux familles de routes :
 *  - /api/artisan/subscription... : initialisation et suivi de l'abonnement par
 *    l'artisan, réservées aux comptes artisans (ROLE_ARTISAN, cf. security.yaml).
 *    On ne les conditionne volontairement pas à isApproved : la souscription est
 *    une relation commerciale directe avec la plateforme (paiement), distincte et
 *    indépendante de la vérification du justificatif par un administrateur.
 *  - /api/stripe/webhook : notifications serveur-à-serveur envoyées par Stripe,
 *    en accès public (cf. security.yaml — PUBLIC_ACCESS) mais authentifiées par
 *    une signature cryptographique (en-tête Stripe-Signature) plutôt qu'un JWT.
 *
 * Rappel de sécurité impératif (cf. ARCHITECTURE.md « Paiement ») : aucune donnée
 * bancaire ne transite par notre serveur. Les routes ci-dessous ne font que
 * produire des URLs vers des pages intégralement hébergées par Stripe (Checkout
 * Session pour payer, Customer Portal pour gérer/résilier) ; nous ne stockons que
 * des identifiants Stripe opaques et un statut, synchronisés par webhook.
 */
class SubscriptionController extends AbstractController
{
    public function __construct(
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly SubscriptionService $subscriptionService,
    ) {
    }

    #[Route('/api/artisan/subscription', name: 'api_artisan_subscription_show', methods: ['GET'])]
    public function show(#[CurrentUser] User $user): JsonResponse
    {
        $artisan = $this->requireArtisan($user);

        return $this->json(['subscription' => $this->serializeSubscription($artisan->getSubscription())]);
    }

    /**
     * Démarre une souscription : crée (au besoin) le Customer Stripe de l'artisan
     * et une session Checkout pour la formule choisie, puis renvoie son URL. Le
     * front doit rediriger l'utilisateur vers cette page hébergée par Stripe.
     */
    #[Route('/api/artisan/subscription/checkout', name: 'api_artisan_subscription_checkout', methods: ['POST'])]
    public function checkout(#[CurrentUser] User $user, Request $request): JsonResponse
    {
        $artisan = $this->requireArtisan($user);

        $dto = $this->deserialize($request, SubscriptionCheckoutRequest::class);
        if ($response = $this->validateOrError($dto)) {
            return $response;
        }

        try {
            $checkoutUrl = $this->subscriptionService->createCheckoutSessionUrl($artisan, $dto->plan);
        } catch (ApiErrorException $exception) {
            return $this->stripeUnavailable($exception);
        }

        return $this->json(['checkoutUrl' => $checkoutUrl]);
    }

    /**
     * Renvoie une URL vers le Customer Portal Stripe : l'artisan y change de
     * formule, met à jour son moyen de paiement ou résilie en autonomie, sans
     * qu'aucune de ces opérations ne transite par notre serveur.
     */
    #[Route('/api/artisan/subscription/portal', name: 'api_artisan_subscription_portal', methods: ['POST'])]
    public function portal(#[CurrentUser] User $user): JsonResponse
    {
        $artisan = $this->requireArtisan($user);

        try {
            $portalUrl = $this->subscriptionService->createBillingPortalSessionUrl($artisan);
        } catch (\RuntimeException $exception) {
            return $this->json(['error' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ApiErrorException $exception) {
            return $this->stripeUnavailable($exception);
        }

        return $this->json(['portalUrl' => $portalUrl]);
    }

    /**
     * Point d'entrée des notifications Stripe (abonnement créé, mis à jour,
     * résilié...). Accès public (cf. security.yaml) mais l'authenticité de l'appel
     * est garantie par une signature HMAC calculée avec STRIPE_WEBHOOK_SECRET et
     * transmise dans l'en-tête Stripe-Signature — c'est ainsi que Stripe authentifie
     * ses propres appels serveur-à-serveur, qui ne peuvent pas porter de JWT.
     */
    #[Route('/api/stripe/webhook', name: 'api_stripe_webhook', methods: ['POST'])]
    public function webhook(Request $request): JsonResponse
    {
        $signature = $request->headers->get('Stripe-Signature', '');
        if ('' === $signature) {
            return $this->json(['error' => 'En-tête Stripe-Signature manquant.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $event = $this->subscriptionService->constructWebhookEvent($request->getContent(), $signature);
        } catch (SignatureVerificationException|\UnexpectedValueException $exception) {
            return $this->json(['error' => 'Signature ou charge utile invalide : '.$exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $this->subscriptionService->applyWebhookEvent($event);

        return $this->json(['received' => true]);
    }

    private function requireArtisan(User $user): Artisan
    {
        $artisan = $user->getArtisan();
        if (null === $artisan) {
            throw $this->createAccessDeniedException('Cet espace est réservé aux artisans.');
        }

        return $artisan;
    }

    private function stripeUnavailable(ApiErrorException $exception): JsonResponse
    {
        return $this->json([
            'error' => 'Le service de paiement est momentanément indisponible. Réessayez plus tard.',
            'detail' => $exception->getMessage(),
        ], Response::HTTP_BAD_GATEWAY);
    }

    private function deserialize(Request $request, string $class): object
    {
        try {
            return $this->serializer->deserialize($request->getContent(), $class, 'json');
        } catch (SerializerExceptionInterface) {
            throw new BadRequestHttpException('Le corps de la requête doit être un JSON valide.');
        }
    }

    private function validateOrError(object $dto): ?JsonResponse
    {
        $violations = $this->validator->validate($dto);
        if (0 === count($violations)) {
            return null;
        }

        return $this->json(['violations' => $this->formatViolations($violations)], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @return list<array{field: string, message: string}>
     */
    private function formatViolations(ConstraintViolationListInterface $violations): array
    {
        $errors = [];
        foreach ($violations as $violation) {
            $errors[] = [
                'field' => $violation->getPropertyPath(),
                'message' => $violation->getMessage(),
            ];
        }

        return $errors;
    }

    private function serializeSubscription(?Subscription $subscription): ?array
    {
        if (null === $subscription) {
            return null;
        }

        return [
            'plan' => $subscription->getPlan(),
            'status' => $subscription->getStatus(),
            'currentPeriodEnd' => $subscription->getCurrentPeriodEnd()?->format(\DateTimeInterface::ATOM),
            'active' => \in_array($subscription->getStatus(), ['active', 'trialing'], true),
        ];
    }
}
