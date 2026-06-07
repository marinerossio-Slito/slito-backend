<?php

namespace App\Repository;

use App\Entity\Review;
use App\Entity\User;
use App\Enum\ReviewAuthorType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Review>
 */
class ReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Review::class);
    }

    /**
     * Note moyenne et nombre d'avis reçus par un artisan (avis laissés par des clients uniquement).
     *
     * @return array{average: float|null, count: int}
     */
    public function getRatingStats(User $artisanUser): array
    {
        $result = $this->createQueryBuilder('r')
            ->select('AVG(r.rating) AS avgRating', 'COUNT(r.id) AS reviewsCount')
            ->andWhere('r.target = :target')
            ->andWhere('r.authorType = :authorType')
            ->setParameter('target', $artisanUser)
            ->setParameter('authorType', ReviewAuthorType::CUSTOMER)
            ->getQuery()
            ->getSingleResult();

        return [
            'average' => null !== $result['avgRating'] ? round((float) $result['avgRating'], 2) : null,
            'count' => (int) $result['reviewsCount'],
        ];
    }
}
