<?php

namespace App\Repository;

use App\Entity\Business;
use App\Entity\Conversation;
use App\Entity\Customer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Conversation>
 */
class ConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conversation::class);
    }

    /**
     * @return Conversation[]
     */
    public function findForCustomer(Customer $customer): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.customer = :customer')
            ->setParameter('customer', $customer)
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Conversation[]
     */
    public function findForBusiness(Business $business): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.business = :business')
            ->setParameter('business', $business)
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
