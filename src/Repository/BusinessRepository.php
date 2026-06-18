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

        $results = array_values($unique);

        // Recherche par mots-clés : nom de l'entreprise, accroche, spécialité
        // libre (denomination choisie par l'artisan) ou nom de la catégorie.
        // Filtree ici (et non en SQL) pour être insensible aux accents de façon
        // portable, sans dependre de l'extension Postgres "unaccent" : "ebeniste"
        // doit retrouver "Ébénisterie".
        if (null !== $keyword && '' !== trim($keyword)) {
            $needle = self::foldForSearch($keyword);

            $results = array_values(array_filter($results, static function (Business $business) use ($needle): bool {
                $haystack = self::foldForSearch(implode(' ', array_filter([
                    $business->getName(),
                    $business->getHeadline(),
                    $business->getSpecialty(),
                    $business->getCategory()?->getName(),
                ])));

                return str_contains($haystack, $needle);
            }));
        }

        return $results;
    }

    /**
     * Normalise une chaîne pour une comparaison insensible à la casse et aux
     * accents (repli des caractères accentués français vers leur équivalent
     * ASCII). Volontairement sans dépendance (pas d'ext-intl ni d'extension SQL).
     */
    private static function foldForSearch(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');

        $map = [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n', 'ÿ' => 'y',
            'œ' => 'oe', 'æ' => 'ae',
        ];

        return strtr($value, $map);
    }
}
