<?php

namespace App\Repository;

use App\Entity\Appointment;
use App\Entity\Artisan;
use App\Entity\Business;
use App\Entity\Customer;
use App\Enum\AppointmentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Appointment>
 */
class AppointmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Appointment::class);
    }

    /**
     * @return Appointment[]
     */
    public function findForCustomer(Customer $customer, ?AppointmentStatus $status = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.customer = :customer')
            ->setParameter('customer', $customer)
            ->orderBy('a.dateTime', 'DESC');

        if (null !== $status) {
            $qb->andWhere('a.status = :status')->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return Appointment[]
     */
    public function findForArtisan(Artisan $artisan, ?AppointmentStatus $status = null): array
    {
        $business = $artisan->getBusiness();
        if (null === $business) {
            return [];
        }

        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.business = :business')
            ->setParameter('business', $business)
            ->orderBy('a.dateTime', 'DESC');

        if (null !== $status) {
            $qb->andWhere('a.status = :status')->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Base clients d'une entreprise : un client par ligne, avec son nombre de
     * rendez-vous et la date du dernier (cf. GET /api/artisan/clients).
     *
     * @return list<array{0: Customer, appointmentsCount: int, lastAppointmentAt: \DateTimeImmutable}>
     */
    public function findClientsForBusiness(Business $business): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('c', 'COUNT(a.id) AS appointmentsCount', 'MAX(a.dateTime) AS lastAppointmentAt')
            ->from(Customer::class, 'c')
            ->innerJoin('c.appointments', 'a', 'WITH', 'a.business = :business')
            ->setParameter('business', $business)
            ->groupBy('c.id')
            ->orderBy('lastAppointmentAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Chiffre d'affaires généré par une entreprise : somme des prix des prestations
     * dont le rendez-vous est COMPLETED (cf. GET /api/artisan/dashboard).
     */
    public function getCompletedRevenue(Business $business): float
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.business = :business')
            ->setParameter('business', $business);

        return $this->sumCompletedRevenue($qb);
    }

    /**
     * Chiffre d'affaires généré sur toute la plateforme (cf. GET /api/admin/stats).
     */
    public function getPlatformCompletedRevenue(): float
    {
        return $this->sumCompletedRevenue($this->createQueryBuilder('a'));
    }

    private function sumCompletedRevenue(QueryBuilder $qb): float
    {
        $total = $qb
            ->select('SUM(s.price)')
            ->innerJoin('a.service', 's')
            ->andWhere('a.status = :status')
            ->setParameter('status', AppointmentStatus::COMPLETED)
            ->getQuery()
            ->getSingleScalarResult();

        return null !== $total ? round((float) $total, 2) : 0.0;
    }
}
