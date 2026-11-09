<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Host;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Host>
 */
class HostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Host::class);
    }

    public function findOneBySpotifyUserId(string $spotifyUserId): ?Host
    {
        return $this->findOneBy(['spotifyUserId' => $spotifyUserId]);
    }

    /** The most recently connected Spotify account. */
    public function findLatest(): ?Host
    {
        return $this->findOneBy([], ['id' => 'DESC']);
    }
}
