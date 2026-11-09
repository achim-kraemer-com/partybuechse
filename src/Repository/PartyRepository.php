<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Host;
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

    /**
     * The host's active parties, the newest first.
     *
     * @return Party[]
     */
    public function findActiveByHost(Host $host): array
    {
        return $this->findBy(['host' => $host, 'active' => true], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * The one party of this host that is allowed to steer Spotify: the most recently started.
     *
     * One Spotify account means one player, so one party. Picking the newest keeps the rule out
     * of the way - starting a party is how the host says which one is running right now.
     */
    public function findCurrentByHost(Host $host): ?Party
    {
        return $this->findActiveByHost($host)[0] ?? null;
    }
}
