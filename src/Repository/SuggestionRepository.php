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
     * The live ranking: the running track and the open wishes, most votes first, older wishes win ties.
     *
     * @return Suggestion[]
     */
    public function findRanking(Party $party): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.party = :party')
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('party', $party)
            ->setParameter('statuses', [SuggestionStatus::Open->value, SuggestionStatus::Playing->value])
            ->orderBy('s.status', \SortDirection::Descending) // "playing" sorts before "open"
            ->addOrderBy('s.voteCount', \SortDirection::Descending)
            ->addOrderBy('s.createdAt', \SortDirection::Ascending)
            ->addOrderBy('s.id', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /** The wish the Partybüchse plays next, or null if nothing is wished for. */
    public function findTopOpen(Party $party): ?Suggestion
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.party = :party')
            ->andWhere('s.status = :status')
            ->setParameter('party', $party)
            ->setParameter('status', SuggestionStatus::Open->value)
            ->orderBy('s.voteCount', \SortDirection::Descending)
            ->addOrderBy('s.createdAt', \SortDirection::Ascending)
            ->addOrderBy('s.id', \SortDirection::Ascending)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The track this party started, i.e. what should be on the speakers.
     *
     * There is at most one; findPlayingAll() exists only to tidy up should a crash ever leave
     * two behind.
     */
    public function findPlaying(Party $party): ?Suggestion
    {
        return $this->findPlayingAll($party)[0] ?? null;
    }

    /** @return Suggestion[] */
    public function findPlayingAll(Party $party): array
    {
        return $this->findBy(['party' => $party, 'status' => SuggestionStatus::Playing], ['startedAt' => 'ASC']);
    }

    /** An open or playing wish for the same track, so duplicates turn into votes. */
    public function findPendingByUri(Party $party, string $uri): ?Suggestion
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.party = :party')
            ->andWhere('s.spotifyUri = :uri')
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('party', $party)
            ->setParameter('uri', $uri)
            ->setParameter('statuses', [SuggestionStatus::Open->value, SuggestionStatus::Playing->value])
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

    /** @return string[] Spotify URIs that are currently open or playing. */
    public function findPendingUris(Party $party): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.spotifyUri')
            ->andWhere('s.party = :party')
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('party', $party)
            ->setParameter('statuses', [SuggestionStatus::Open->value, SuggestionStatus::Playing->value])
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'spotifyUri');
    }
}
