<?php

namespace App\Repository;

use App\Entity\Business;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Business>
 */
class BusinessRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Business::class);
    }

    /**
     * Recherche de fiches entreprise tenues par des artisans approuvés, avec filtres optionnels
     * (catégorie, ville, fourchette de prix des prestations). Le filtre de note minimale
     * s'applique ensuite côté contrôleur, car il dépend des avis (entité distincte de Business).
     *
     * @return Business[]
     */
    public function search(?string $categorySlug, ?string $city, ?float $minPrice, ?float $maxPrice, ?string $keyword = null): array
    {
        $qb = $this->createQueryBuilder('b')
            ->innerJoin('b.artisan', 'a')->addSelect('a')
            ->leftJoin('b.category', 'c')->addSelect('c')
            ->andWhere('a.isApproved = :approved')
            ->setParameter('approved', true);

        if (null !== $categorySlug) {
            $qb->andWhere('c.slug = :categorySlug')
                ->setParameter('categorySlug', $categorySlug);
        }

        // Recherche par mots-clés : nom de l'entreprise, accroche, spécialité
        // libre (denomination choisie par l'artisan) ou nom de la catégorie.
        if (null !== $keyword) {
            $qb->andWhere('LOWER(b.name) LIKE :keyword OR LOWER(b.headline) LIKE :keyword OR LOWER(b.specialty) LIKE :keyword OR LOWER(c.name) LIKE :keyword')
                ->setParameter('keyword', '%'.mb_strtolower($keyword).'%');
        }

        if (null !== $city) {
            $qb->andWhere('LOWER(b.officeAddress) LIKE :city')
                ->setParameter('city', '%'.mb_strtolower($city).'%');
        }

        if (null !== $minPrice || null !== $maxPrice) {
            $qb->innerJoin('b.services', 's');

            if (null !== $minPrice) {
                $qb->andWhere('s.price >= :minPrice')->setParameter('minPrice', $minPrice);
            }

            if (null !== $maxPrice) {
                $qb->andWhere('s.price <= :maxPrice')->setParameter('maxPrice', $maxPrice);
            }
        }

        $businesses = $qb->orderBy('b.name', 'ASC')->getQuery()->getResult();

        // Le INNER JOIN vers services peut multiplier les lignes (une par prestation
        // correspondante) ; on déduplique par id plutôt que d'utiliser DISTINCT en SQL,
        // car PostgreSQL ne sait pas comparer ses colonnes de type json (paymentMethods, workingHours).
        $unique = [];
        foreach ($businesses as $business) {
            $unique[$business->getId()] = $business;
        }

        return array_values($unique);
    }
}
