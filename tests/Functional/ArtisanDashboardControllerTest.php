<?php

namespace App\Tests\Functional;

use App\Entity\Artisan;
use App\Entity\ArtisanCategory;

/**
 * Espace artisan (étape 7 du cahier des charges) : KPIs d'activité, agenda,
 * gestion de la fiche entreprise et base clients (cf. ArtisanDashboardController).
 * Réservé aux artisans dont le compte a été validé par un administrateur (isApproved).
 */
class ArtisanDashboardControllerTest extends ApiTestCase
{
    public function testApprovedArtisanSeesTheirDashboardKpis(): void
    {
        $artisan = $this->demoEntity(Artisan::class, ['isApproved' => true]);
        $business = $artisan->getBusiness();
        self::assertNotNull($business);

        $this->loginAs($artisan->getUser());

        $response = $this->jsonRequest('GET', '/api/artisan/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSame($business->getId(), $response['business']['id']);
        self::assertArrayHasKey('total', $response['appointments']);
        self::assertArrayHasKey('byStatus', $response['appointments']);
        self::assertArrayHasKey('PENDING', $response['appointments']['byStatus']);
        self::assertArrayHasKey('revenue', $response);
        self::assertArrayHasKey('rating', $response);
    }

    public function testPendingArtisanCannotAccessTheDashboard(): void
    {
        $artisan = $this->demoEntity(Artisan::class, ['isApproved' => false]);
        $this->loginAs($artisan->getUser());

        $this->client->jsonRequest('GET', '/api/artisan/dashboard');

        self::assertSame(403, $this->statusCode());
    }

    public function testApprovedArtisanSeesTheirCalendar(): void
    {
        $artisan = $this->demoEntity(Artisan::class, ['isApproved' => true]);
        $this->loginAs($artisan->getUser());

        $response = $this->jsonRequest('GET', '/api/artisan/calendar');

        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('events', $response);
        self::assertArrayHasKey('appointments', $response);

        // Seuls les rendez-vous actifs (en attente ou confirmés) figurent dans l'agenda
        // (cf. ArtisanDashboardController::calendar)
        foreach ($response['appointments'] as $appointmentRef) {
            self::assertContains($appointmentRef['status'], ['PENDING', 'CONFIRMED']);
        }
    }

    public function testApprovedArtisanCanUpdateTheirExistingBusiness(): void
    {
        $artisan = $this->demoEntity(Artisan::class, ['isApproved' => true]);
        $business = $artisan->getBusiness();
        self::assertNotNull($business);
        $category = $business->getCategory();
        self::assertNotNull($category);

        $this->loginAs($artisan->getUser());

        $response = $this->jsonRequest('PUT', '/api/artisan/business', [
            'name' => 'Nouvelle Raison Sociale',
            'categoryId' => $category->getId(),
            'headline' => 'Un nouvel intitulé pour ma fiche',
            'description' => 'Description fraîchement mise à jour pour ce test.',
            'contactNumber' => '0102030405',
            'officeAddress' => '1 rue du Test, 75000 Paris',
            'paymentMethods' => ['Carte bancaire', 'Espèces'],
            'workingHours' => ['lundi' => ['09:00', '17:00']],
            'replyDelay' => 'Répond généralement en moins de 24 heures',
        ]);

        self::assertSame(200, $this->statusCode(), 'Une fiche déjà existante doit être mise à jour (HTTP 200), pas recréée.');
        self::assertSame('Nouvelle Raison Sociale', $response['name']);
        self::assertSame($category->getId(), $response['category']['id']);
        self::assertSame('Un nouvel intitulé pour ma fiche', $response['headline']);
    }

    public function testNewlyApprovedArtisanCanCreateTheirBusiness(): void
    {
        // On approuve un artisan candidat (encore sans fiche, cf. AppFixtures::createArtisans)
        $artisan = $this->demoEntity(Artisan::class, ['isApproved' => false]);
        self::assertNull($artisan->getBusiness());
        $artisan->setIsApproved(true);
        $this->entityManager()->flush();

        $category = $this->demoEntity(ArtisanCategory::class, ['slug' => 'serrurerie']);

        $this->loginAs($artisan->getUser());

        $response = $this->jsonRequest('PUT', '/api/artisan/business', [
            'name' => 'Ma Toute Nouvelle Entreprise',
            'categoryId' => $category->getId(),
        ]);

        self::assertSame(201, $this->statusCode(), 'Une fiche encore inexistante doit être créée (HTTP 201).');
        self::assertSame('Ma Toute Nouvelle Entreprise', $response['name']);

        $this->entityManager()->refresh($artisan);
        self::assertNotNull($artisan->getBusiness());
        self::assertSame('Ma Toute Nouvelle Entreprise', $artisan->getBusiness()->getName());
    }

    public function testUpsertBusinessRejectsAnUnknownCategory(): void
    {
        $artisan = $this->demoEntity(Artisan::class, ['isApproved' => true]);
        $this->loginAs($artisan->getUser());

        $response = $this->jsonRequest('PUT', '/api/artisan/business', [
            'name' => 'Peu importe le nom',
            'categoryId' => 999999,
        ]);

        self::assertSame(404, $this->statusCode());
        self::assertArrayHasKey('error', $response);
    }

    public function testUpsertBusinessValidatesRequiredFields(): void
    {
        $artisan = $this->demoEntity(Artisan::class, ['isApproved' => true]);
        $this->loginAs($artisan->getUser());

        $response = $this->jsonRequest('PUT', '/api/artisan/business', [
            'headline' => 'Sans nom ni catégorie',
        ]);

        self::assertSame(422, $this->statusCode());
        $fields = array_column($response['violations'], 'field');
        self::assertContains('name', $fields);
        self::assertContains('categoryId', $fields);
    }

    public function testApprovedArtisanSeesTheirClientList(): void
    {
        $artisan = $this->demoEntity(Artisan::class, ['isApproved' => true]);
        $this->loginAs($artisan->getUser());

        $response = $this->jsonRequest('GET', '/api/artisan/clients');

        self::assertResponseIsSuccessful();

        foreach ($response as $row) {
            self::assertArrayHasKey('customer', $row);
            self::assertArrayHasKey('appointmentsCount', $row);
            self::assertArrayHasKey('lastAppointmentAt', $row);
            self::assertGreaterThan(0, $row['appointmentsCount']);
        }
    }
}
