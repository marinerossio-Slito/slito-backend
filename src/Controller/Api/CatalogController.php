<?php

namespace App\Controller\Api;

use App\Entity\ArtisanCategory;
use App\Entity\Business;
use App\Entity\Service;
use App\Repository\ArtisanCategoryRepository;
use App\Repository\BusinessRepository;
use App\Repository\ReviewRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints publics de consultation des fiches artisan : catégories, recherche, détail.
 * Correspond à la section « Recherche & fiches » du cahier des charges (étape 4).
 */
#[Route('/api')]
class CatalogController extends AbstractController
{
    public function __construct(
        private readonly ArtisanCategoryRepository $categoryRepository,
        private readonly BusinessRepository $businessRepository,
        private readonly ReviewRepository $reviewRepository,
    ) {
    }

    #[Route('/categories', name: 'api_categories', methods: ['GET'])]
    public function categories(): JsonResponse
    {
        $categories = $this->categoryRepository->findBy([], ['name' => 'ASC']);

        return $this->json(array_map($this->serializeCategory(...), $categories));
    }

    #[Route('/businesses/{id<\d+>}', name: 'api_business_show', methods: ['GET'])]
    public function showBusiness(int $id): JsonResponse
    {
        $business = $this->businessRepository->find($id);
        $artisan = $business?->getArtisan();

        if (null === $business || null === $artisan || !$artisan->isApproved()) {
            throw $this->createNotFoundException('Fiche introuvable.');
        }

        $ratingStats = $this->reviewRepository->getRatingStats($artisan->getUser());

        return $this->json($this->serializeBusinessDetail($business, $ratingStats));
    }

    /**
     * Filtres acceptés en query string : category (slug), city, minPrice, maxPrice, minRating.
     * Le filtre de note minimale s'applique ici (et non en SQL) car la moyenne dépend
     * des avis, une entité distincte de Business.
     */
    #[Route('/search', name: 'api_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $category = $this->queryString($request, 'category');
        $city = $this->queryString($request, 'city');
        $minPrice = $this->queryFloat($request, 'minPrice');
        $maxPrice = $this->queryFloat($request, 'maxPrice');
        $minRating = $this->queryFloat($request, 'minRating');

        $businesses = $this->businessRepository->search($category, $city, $minPrice, $maxPrice);

        $results = [];
        foreach ($businesses as $business) {
            $artisan = $business->getArtisan();
            if (null === $artisan) {
                continue;
            }

            $ratingStats = $this->reviewRepository->getRatingStats($artisan->getUser());

            if (null !== $minRating && (null === $ratingStats['average'] || $ratingStats['average'] < $minRating)) {
                continue;
            }

            $results[] = $this->serializeBusinessSummary($business, $ratingStats);
        }

        return $this->json($results);
    }

    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query->get($key);
        $value = \is_string($value) ? trim($value) : null;

        return ('' === $value) ? null : $value;
    }

    private function queryFloat(Request $request, string $key): ?float
    {
        $value = $this->queryString($request, $key);

        return null === $value ? null : (float) $value;
    }

    private function serializeCategory(ArtisanCategory $category): array
    {
        return [
            'id' => $category->getId(),
            'name' => $category->getName(),
            'icon' => $category->getIcon(),
            'slug' => $category->getSlug(),
        ];
    }

    private function serializeService(Service $service): array
    {
        return [
            'id' => $service->getId(),
            'name' => $service->getName(),
            'description' => $service->getDescription(),
            'duration' => $service->getDuration(),
            'price' => $service->getPrice(),
            'location' => $service->getLocation()?->value,
            'faq' => $service->getFaq(),
        ];
    }

    /**
     * @param array{average: float|null, count: int} $ratingStats
     */
    private function serializeBusinessSummary(Business $business, array $ratingStats): array
    {
        $prices = array_map(
            static fn (Service $service): float => (float) $service->getPrice(),
            $business->getServices()->toArray(),
        );

        return [
            'id' => $business->getId(),
            'name' => $business->getName(),
            'headline' => $business->getHeadline(),
            'coverImage' => $business->getCoverImage(),
            'officeAddress' => $business->getOfficeAddress(),
            'category' => null !== $business->getCategory() ? $this->serializeCategory($business->getCategory()) : null,
            'averageRating' => $ratingStats['average'],
            'reviewsCount' => $ratingStats['count'],
            'priceFrom' => [] === $prices ? null : min($prices),
        ];
    }

    /**
     * @param array{average: float|null, count: int} $ratingStats
     */
    private function serializeBusinessDetail(Business $business, array $ratingStats): array
    {
        return [
            ...$this->serializeBusinessSummary($business, $ratingStats),
            'description' => $business->getDescription(),
            'website' => $business->getWebsite(),
            'paymentMethods' => $business->getPaymentMethods(),
            'contactNumber' => $business->getContactNumber(),
            'workingHours' => $business->getWorkingHours(),
            'replyDelay' => $business->getReplyDelay(),
            'services' => array_map($this->serializeService(...), $business->getServices()->toArray()),
        ];
    }
}
