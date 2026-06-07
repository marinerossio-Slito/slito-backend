<?php

namespace App\Repository;

use App\Entity\Appointment;
use App\Entity\Artisan;
use App\Entity\Customer;
use App\Enum\AppointmentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
}
