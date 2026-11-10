<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Guest;
use App\Entity\Suggestion;
use App\Entity\SuggestionStatus;
use App\Entity\Vote;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Vote>
 */
class VoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Vote::class);
    }

    public function findOneByGuestAndSuggestion(Guest $guest, Suggestion $suggestion): ?Vote
    {
        return $this->findOneBy(['guest' => $guest, 'suggestion' => $suggestion]);
    }

    /** Votes that are still "spent": the wish is open or playing. */
    public function countActiveVotesForGuest(Guest $guest): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->join('v.suggestion', 's')
            ->andWhere('v.guest = :guest')
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('guest', $guest)
            ->setParameter('statuses', [SuggestionStatus::Open->value, SuggestionStatus::Playing->value])
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return int[] suggestion ids the guest has voted for (open or playing only) */
    public function findActiveSuggestionIdsForGuest(Guest $guest): array
    {
        $rows = $this->createQueryBuilder('v')
            ->select('IDENTITY(v.suggestion) AS sid')
            ->join('v.suggestion', 's')
            ->andWhere('v.guest = :guest')
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('guest', $guest)
            ->setParameter('statuses', [SuggestionStatus::Open->value, SuggestionStatus::Playing->value])
            ->getQuery()
            ->getScalarResult();

        return array_map('intval', array_column($rows, 'sid'));
    }
}
