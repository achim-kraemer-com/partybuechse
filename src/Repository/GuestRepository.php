<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Guest;
use App\Entity\Party;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Guest>
 */
class GuestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Guest::class);
    }

    public function findOneByPartyAndToken(Party $party, string $token): ?Guest
    {
        return $this->findOneBy(['party' => $party, 'token' => $token]);
    }

    public function countByParty(Party $party): int
    {
        return $this->count(['party' => $party]);
    }
}
