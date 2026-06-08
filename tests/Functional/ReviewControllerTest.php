<?php

namespace App\Tests\Functional;

use App\Entity\Appointment;
use App\Entity\Customer;
use App\Entity\Review;
use App\Entity\Service;
use App\Enum\AppointmentStatus;
use App\Enum\Location;
use App\Enum\ReviewAuthorType;

/**
 * Avis bidirectionnels (étape 6 du cahier des charges) : un avis ne peut être posté
 * qu'une fois la prestation terminée, par le client ou l'artisan concerné, une seule
 * fois chacun (cf. ReviewController).
 *
 * Plutôt que de chercher un rendez-vous COMPLETED du jeu de démonstration qui n'a
 * encore reçu aucun avis (AppFixtures en distribue déjà aléatoirement, ce qui rendrait
 * ces tests fragiles), on crée ici un rendez-vous COMPLETED « frais » entre un client
 * et un artisan existants : il est garanti vierge de tout avis.
 */
class ReviewControllerTest extends ApiTestCase
{
    public function testCustomerCanReviewTheArtisanAfterACompletedAppointment(): void
    {
        $appointment = $this->freshCompletedAppointment();
        $customerUser = $appointment->getCustomer()?->getUser();
        $artisanUser = $appointment->getBusiness()?->getArtisan()?->getUser();
        self::assertNotNull($customerUser);
        self::assertNotNull($artisanUser);

        $this->loginAs($customerUser);

        $response = $this->jsonRequest('POST', '/api/reviews', [
            'appointmentId' => $appointment->getId(),
            'rating' => 5,
            'punctualityRating' => 4,
            'qualityRating' => 5,
            'comment' => 'Travail soigné et ponctuel, je recommande.',
        ]);

        self::assertSame(201, $this->statusCode());
        self::assertSame('CUSTOMER', $response['authorType']);
        self::assertSame($customerUser->getId(), $response['author']['id']);
        self::assertSame($artisanUser->getId(), $response['target']['id']);
        self::assertSame(5, $response['rating']);
    }

    public function testArtisanCanReviewTheCustomerAfterACompletedAppointment(): void
    {
        $appointment = $this->freshCompletedAppointment();
        $customerUser = $appointment->getCustomer()?->getUser();
        $artisanUser = $appointment->getBusiness()?->getArtisan()?->getUser();
        self::assertNotNull($customerUser);
        self::assertNotNull($artisanUser);

        $this->loginAs($artisanUser);

        $response = $this->jsonRequest('POST', '/api/reviews', [
            'appointmentId' => $appointment->getId(),
            'rating' => 4,
            'comment' => 'Client clair dans sa demande, accès facile au logement.',
        ]);

        self::assertSame(201, $this->statusCode());
        self::assertSame('ARTISAN', $response['authorType']);
        self::assertSame($artisanUser->getId(), $response['author']['id']);
        self::assertSame($customerUser->getId(), $response['target']['id']);
    }

    public function testCannotReviewAnAppointmentThatIsNotCompletedYet(): void
    {
        $appointment = $this->demoEntity(Appointment::class, ['status' => AppointmentStatus::PENDING]);
        $customerUser = $appointment->getCustomer()?->getUser();
        self::assertNotNull($customerUser);

        $this->loginAs($customerUser);

        $response = $this->jsonRequest('POST', '/api/reviews', [
            'appointmentId' => $appointment->getId(),
            'rating' => 5,
        ]);

        self::assertSame(422, $this->statusCode());
        self::assertArrayHasKey('error', $response);
    }

    public function testCannotSubmitTheSameReviewTwice(): void
    {
        $appointment = $this->freshCompletedAppointment();
        $customerUser = $appointment->getCustomer()?->getUser();
        $artisanUser = $appointment->getBusiness()?->getArtisan()?->getUser();
        self::assertNotNull($customerUser);
        self::assertNotNull($artisanUser);

        // On dépose le premier avis directement en base plutôt que via l'API : un
        // même test authentifié ne doit enchaîner qu'une seule requête HTTP (le
        // KernelBrowser de test ne garantit la persistance du jeton loginUser()
        // que pour la requête courante, cf. ApiTestCase::loginAs). La détection du
        // doublon, elle, repose uniquement sur une recherche en base
        // (cf. ReviewController::create), peu importe l'origine du premier avis.
        $entityManager = $this->entityManager();
        $existingReview = (new Review())
            ->setRating(5)
            ->setAuthorType(ReviewAuthorType::CUSTOMER)
            ->setAppointment($appointment)
            ->setAuthor($customerUser)
            ->setTarget($artisanUser);
        $entityManager->persist($existingReview);
        $entityManager->flush();

        $this->loginAs($customerUser);

        $second = $this->jsonRequest('POST', '/api/reviews', ['appointmentId' => $appointment->getId(), 'rating' => 5]);
        self::assertSame(409, $this->statusCode());
        self::assertArrayHasKey('error', $second);
    }

    public function testUnrelatedUserCannotReviewAnAppointment(): void
    {
        $appointment = $this->freshCompletedAppointment();
        $customerUser = $appointment->getCustomer()?->getUser();

        $otherCustomer = null;
        foreach ($this->entityManager()->getRepository(Customer::class)->findAll() as $candidate) {
            if ($candidate->getUser() !== $customerUser) {
                $otherCustomer = $candidate;
                break;
            }
        }
        self::assertNotNull($otherCustomer);

        $this->loginAs($otherCustomer->getUser());

        $this->client->jsonRequest('POST', '/api/reviews', [
            'appointmentId' => $appointment->getId(),
            'rating' => 3,
        ]);

        self::assertSame(403, $this->statusCode());
    }

    public function testReviewRequiresARatingBetweenOneAndFive(): void
    {
        $appointment = $this->freshCompletedAppointment();
        $customerUser = $appointment->getCustomer()?->getUser();
        self::assertNotNull($customerUser);

        $this->loginAs($customerUser);

        $response = $this->jsonRequest('POST', '/api/reviews', [
            'appointmentId' => $appointment->getId(),
            'rating' => 8,
        ]);

        self::assertSame(422, $this->statusCode());
        self::assertContains('rating', array_column($response['violations'], 'field'));
    }

    /**
     * Construit un rendez-vous COMPLETED « frais » (sans avis), entre un client et
     * un artisan approuvé du jeu de démonstration, pour que les tests d'avis
     * partent toujours d'un état neutre et reproductible.
     */
    private function freshCompletedAppointment(): Appointment
    {
        $customer = $this->demoEntity(Customer::class);
        $service = $this->demoEntity(Service::class);
        $business = $service->getBusiness();
        self::assertNotNull($business);

        $appointment = (new Appointment())
            ->setDateTime(new \DateTimeImmutable('-10 days'))
            ->setStatus(AppointmentStatus::COMPLETED)
            ->setLocation(Location::HOME)
            ->setService($service)
            ->setCustomer($customer)
            ->setBusiness($business);

        $entityManager = $this->entityManager();
        $entityManager->persist($appointment);
        $entityManager->flush();

        return $appointment;
    }
}
