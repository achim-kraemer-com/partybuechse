<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Guest;
use App\Entity\Party;
use App\Entity\Suggestion;
use App\Entity\SuggestionStatus;
use App\Entity\Vote;
use App\Repository\SuggestionRepository;
use App\Repository\VoteRepository;
use App\Spotify\TrackInfo;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The rules of the game:
 *  - every guest has N votes (party setting, default 3)
 *  - a wish costs one vote (the guest automatically votes for their own wish)
 *  - votes come back once the track has played or the host removed the wish
 *  - a track can only be pending once per party; a second wish turns into a vote
 */
class VotingService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SuggestionRepository $suggestions,
        private readonly VoteRepository $votes,
        private readonly ClockInterface $clock,
    ) {
    }

    public function votesLeft(Guest $guest): int
    {
        return max(0, $guest->getParty()->getVotesPerGuest() - $this->votes->countActiveVotesForGuest($guest));
    }

    /**
     * @throws VotingException
     */
    public function suggest(Guest $guest, TrackInfo $track): Suggestion
    {
        $party = $guest->getParty();
        $this->assertPartyActive($party);

        if (!SpotifyUri::isTrackUri($track->uri)) {
            throw new VotingException('Das ist kein gültiger Spotify-Titel.');
        }

        $existing = $this->suggestions->findPendingByUri($party, $track->uri);
        if (null !== $existing) {
            if (null === $this->votes->findOneByGuestAndSuggestion($guest, $existing)) {
                $this->vote($guest, $existing);
            }

            return $existing;
        }

        if ($this->votesLeft($guest) < 1) {
            throw new VotingException('Du hast keine Stimme mehr frei. Sobald einer deiner Wünsche läuft, bekommst du sie zurück.');
        }

        $now = $this->clock->now();
        $suggestion = new Suggestion($party, $guest, $track, $now);
        $this->entityManager->persist($suggestion);

        $vote = new Vote($suggestion, $guest, $now);
        $suggestion->incrementVoteCount();
        $this->entityManager->persist($vote);
        $this->entityManager->flush();

        return $suggestion;
    }

    /**
     * @throws VotingException
     */
    public function vote(Guest $guest, Suggestion $suggestion): void
    {
        $this->assertSameParty($guest, $suggestion);
        $this->assertPartyActive($guest->getParty());

        if (!$suggestion->isOpen()) {
            throw new VotingException(match ($suggestion->getStatus()) {
                SuggestionStatus::Playing => 'Der Titel läuft gerade, Stimmen sind nicht mehr nötig.',
                SuggestionStatus::Played => 'Der Titel wurde schon gespielt.',
                default => 'Der Wunsch ist nicht mehr in der Liste.',
            });
        }

        if (null !== $this->votes->findOneByGuestAndSuggestion($guest, $suggestion)) {
            return; // idempotent
        }

        if ($this->votesLeft($guest) < 1) {
            throw new VotingException('Du hast keine Stimme mehr frei. Nimm eine Stimme zurück oder warte, bis ein Wunsch gespielt wurde.');
        }

        $this->entityManager->persist(new Vote($suggestion, $guest, $this->clock->now()));
        $suggestion->incrementVoteCount();
        $this->entityManager->flush();
    }

    /**
     * @throws VotingException
     */
    public function unvote(Guest $guest, Suggestion $suggestion): void
    {
        $this->assertSameParty($guest, $suggestion);

        $vote = $this->votes->findOneByGuestAndSuggestion($guest, $suggestion);
        if (null === $vote) {
            return;
        }
        if (!$suggestion->isOpen()) {
            throw new VotingException('Der Titel ist schon unterwegs zu Spotify, die Stimme kann nicht mehr zurückgenommen werden.');
        }

        $this->entityManager->remove($vote);
        $suggestion->decrementVoteCount();
        $this->entityManager->flush();
    }

    /**
     * Toggles the guest's vote and reports whether a vote now exists.
     *
     * @throws VotingException
     */
    public function toggleVote(Guest $guest, Suggestion $suggestion): bool
    {
        if (null !== $this->votes->findOneByGuestAndSuggestion($guest, $suggestion)) {
            $this->unvote($guest, $suggestion);

            return false;
        }

        $this->vote($guest, $suggestion);

        return true;
    }

    /** Host action: drop a wish from the list; its votes are freed. */
    public function remove(Suggestion $suggestion): void
    {
        if (!$suggestion->getStatus()->bindsVotes()) {
            return;
        }
        $suggestion->markRemoved();
        $this->entityManager->flush();
    }

    private function assertPartyActive(Party $party): void
    {
        if (!$party->isActive()) {
            throw new VotingException('Die Party ist beendet.');
        }
    }

    private function assertSameParty(Guest $guest, Suggestion $suggestion): void
    {
        if ($guest->getParty()->getId() !== $suggestion->getParty()->getId()) {
            throw new VotingException('Dieser Wunsch gehört zu einer anderen Party.');
        }
    }
}
