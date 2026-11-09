<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Party;
use App\Entity\Suggestion;
use App\Entity\SuggestionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Suggestion>
 */
class SuggestionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Suggestion::class);
    }

    /**
     * The live ranking: open and queued wishes, most votes first, older wishes win ties.
     *
     * @return Suggestion[]
     */
    public function findRanking(Party $party): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.party = :party')
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('party', $party)
            ->setParameter('statuses', [SuggestionStatus::Open->value, SuggestionStatus::Queued->value])
            ->orderBy('s.status', 'DESC') // "queued" sorts before "open"
            ->addOrderBy('s.voteCount', 'DESC')
            ->addOrderBy('s.createdAt', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** The wish that should be handed to Spotify next, or null if the ranking is empty. */
    public function findTopOpen(Party $party): ?Suggestion
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.party = :party')
            ->andWhere('s.status = :status')
            ->setParameter('party', $party)
            ->setParameter('status', SuggestionStatus::Open->value)
            ->orderBy('s.voteCount', 'DESC')
            ->addOrderBy('s.createdAt', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return Suggestion[] */
    public function findQueued(Party $party): array
    {
        return $this->findBy(['party' => $party, 'status' => SuggestionStatus::Queued], ['queuedAt' => 'ASC']);
    }

    /**
     * A wish this host handed to Spotify in a *different* party and that never played.
     *
     * Spotify's queue belongs to the account, not to the party, and the API has no way to take
     * a track back out of it. A wish that was handed over shortly before its party ended
     * therefore comes on during the next party: the guests hear a song nobody there wished for.
     * "queued" is exactly the record of "handed over, not played yet".
     */
    public function findQueuedInAnotherParty(Party $party, string $uri): ?Suggestion
    {
        return $this->createQueryBuilder('s')
            ->join('s.party', 'p')
            ->andWhere('p.host = :host')
            ->andWhere('p != :party')
            ->andWhere('s.spotifyUri = :uri')
            ->andWhere('s.status = :queued')
            ->setParameter('host', $party->getHost())
            ->setParameter('party', $party)
            ->setParameter('uri', $uri)
            ->setParameter('queued', SuggestionStatus::Queued->value)
            ->orderBy('s.queuedAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** An open or queued wish for the same track, so duplicates turn into votes. */
    public function findPendingByUri(Party $party, string $uri): ?Suggestion
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.party = :party')
            ->andWhere('s.spotifyUri = :uri')
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('party', $party)
            ->setParameter('uri', $uri)
            ->setParameter('statuses', [SuggestionStatus::Open->value, SuggestionStatus::Queued->value])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return Suggestion[] */
    public function findRecentlyPlayed(Party $party, int $limit = 5): array
    {
        return $this->findBy(['party' => $party, 'status' => SuggestionStatus::Played], ['playedAt' => 'DESC'], $limit);
    }

    /** The most recently played wish for a track, to credit the guest on the "now playing" card. */
    public function findLastPlayedByUri(Party $party, string $uri): ?Suggestion
    {
        return $this->findOneBy(
            ['party' => $party, 'spotifyUri' => $uri, 'status' => SuggestionStatus::Played],
            ['playedAt' => 'DESC'],
        );
    }

    /** @return string[] Spotify URIs that are currently open or queued. */
    public function findPendingUris(Party $party): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.spotifyUri')
            ->andWhere('s.party = :party')
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('party', $party)
            ->setParameter('statuses', [SuggestionStatus::Open->value, SuggestionStatus::Queued->value])
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'spotifyUri');
    }
}
