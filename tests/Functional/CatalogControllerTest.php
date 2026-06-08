<?php

namespace App\Tests\Functional;

use App\Entity\Artisan;
use App\Entity\ArtisanCategory;
use App\Entity\Business;

/**
 * Catalogue public (étape 4 du cahier des charges) : catégories, fiche détaillée
 * d'une entreprise et recherche filtrée. Routes accessibles sans authentification
 * (cf. access_control dans security.yaml).
 */
class CatalogControllerTest extends ApiTestCase
{
    public function testCategoriesListsAllCategoriesAlphabetically(): void
    {
        $response = $this->jsonRequest('GET', '/api/categories');

        self::assertResponseIsSuccessful();
        // 8 catégories de métiers sont créées par AppFixtures::createCategories
        self::assertCount(8, $response);

        foreach ($response as $category) {
            self::assertArrayHasKey('id', $category);
            self::assertArrayHasKey('name', $category);
            self::assertArrayHasKey('icon', $category);
            self::assertArrayHasKey('slug', $category);
        }

        // Le contrôleur délègue le tri à la base (ORDER BY name ASC, cf.
        // CatalogController::categories) : la collation PostgreSQL traite les
        // accents différemment d'un tri octet-à-octet en PHP (ex. "Électricité"
        // n'est pas classé en fin de liste). On compare donc à la même requête
        // plutôt que de réimplémenter cet ordre, ce qui serait fragile.
        $namesFromRepository = array_map(
            static fn (ArtisanCategory $category): ?string => $category->getName(),
            $this->entityManager()->getRepository(ArtisanCategory::class)->findBy([], ['name' => 'ASC']),
        );
        self::assertSame($namesFromRepository, array_column($response, 'name'), 'Les catégories doivent être triées par nom (cf. CatalogController::categories).');
    }

    public function testShowBusinessReturnsFullDetailForApprovedArtisan(): void
    {
        $artisan = $this->demoEntity(Artisan::class, ['isApproved' => true]);
        $business = $artisan->getBusiness();
        self::assertNotNull($business);

        $response = $this->jsonRequest('GET', \sprintf('/api/businesses/%d', $business->getId()));

        self::assertResponseIsSuccessful();
        self::assertSame($business->getId(), $response['id']);
        self::assertSame($business->getName(), $response['name']);
        self::assertArrayHasKey('description', $response);
        self::assertArrayHasKey('services', $response);
        self::assertArrayHasKey('averageRating', $response);
        self::assertArrayHasKey('reviewsCount', $response);
        self::assertNotEmpty($response['services'], 'Chaque entreprise approuvée a au moins deux prestations (cf. AppFixtures::createBusiness).');

        foreach ($response['services'] as $service) {
            self::assertArrayHasKey('id', $service);
            self::assertArrayHasKey('name', $service);
            self::assertArrayHasKey('price', $service);
            self::assertArrayHasKey('duration', $service);
        }
    }

    public function testShowBusinessReturns404WhenBusinessDoesNotExist(): void
    {
        $this->client->jsonRequest('GET', '/api/businesses/999999');

        self::assertSame(404, $this->statusCode());
    }

    public function testShowBusinessReturns404WhenArtisanIsNotApproved(): void
    {
        $artisan = $this->demoEntity(Artisan::class, ['isApproved' => true]);
        $business = $artisan->getBusiness();
        self::assertNotNull($business);

        // Simule la révocation de la validation d'un artisan par un administrateur :
        // sa fiche ne doit plus être exposée publiquement (cf. CatalogController::showBusiness).
        $artisan->setIsApproved(false);
        $this->entityManager()->flush();

        $this->client->jsonRequest('GET', \sprintf('/api/businesses/%d', $business->getId()));

        self::assertSame(404, $this->statusCode());
    }

    public function testSearchWithoutFiltersReturnsApprovedBusinesses(): void
    {
        $response = $this->jsonRequest('GET', '/api/search');

        self::assertResponseIsSuccessful();
        // 8 catégories => 8 artisans approuvés, chacun avec une fiche (cf. AppFixtures)
        self::assertCount(8, $response);

        foreach ($response as $summary) {
            self::assertArrayHasKey('averageRating', $summary);
            self::assertArrayHasKey('reviewsCount', $summary);
            self::assertArrayHasKey('priceFrom', $summary);
            self::assertArrayHasKey('category', $summary);
        }
    }

    public function testSearchFiltersByCategorySlug(): void
    {
        $category = $this->demoEntity(ArtisanCategory::class, ['slug' => 'plomberie']);

        $response = $this->jsonRequest('GET', '/api/search?category=plomberie');

        self::assertResponseIsSuccessful();
        self::assertNotEmpty($response);
        foreach ($response as $summary) {
            self::assertSame($category->getId(), $summary['category']['id']);
        }
    }

    public function testSearchFiltersByMinimumRating(): void
    {
        $unfiltered = $this->jsonRequest('GET', '/api/search');
        $filtered = $this->jsonRequest('GET', '/api/search?minRating=4.5');

        self::assertResponseIsSuccessful();
        self::assertLessThanOrEqual(\count($unfiltered), \count($filtered));

        foreach ($filtered as $summary) {
            self::assertNotNull($summary['averageRating']);
            self::assertGreaterThanOrEqual(4.5, $summary['averageRating']);
        }
    }

    public function testSearchWithImpossiblePriceRangeReturnsNoResults(): void
    {
        $response = $this->jsonRequest('GET', '/api/search?minPrice=100000&maxPrice=200000');

        self::assertResponseIsSuccessful();
        self::assertSame([], $response);
    }
}
