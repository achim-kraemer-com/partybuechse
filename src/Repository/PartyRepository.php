<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Party;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Party>
 */
class PartyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Party::class);
    }

    public function findOneByCode(string $code): ?Party
    {
        return $this->findOneBy(['code' => strtoupper(trim($code))]);
    }

    public function codeExists(string $code): bool
    {
        return null !== $this->findOneByCode($code);
    }

    /** @return Party[] */
    public function findActive(): array
    {
        return $this->findBy(['active' => true], ['createdAt' => 'DESC']);
    }
}
