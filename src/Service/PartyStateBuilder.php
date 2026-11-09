<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Guest;
use App\Entity\Party;
use App\Entity\Suggestion;
use App\Repository\GuestRepository;
use App\Repository\SuggestionRepository;
use App\Repository\VoteRepository;

/**
 * Assembles the JSON state that the guest app, the host dashboard and the display poll.
 */
class PartyStateBuilder
{
    public function __construct(
        private readonly SuggestionRepository $suggestions,
        private readonly VoteRepository $votes,
        private readonly GuestRepository $guests,
        private readonly VotingService $voting,
    ) {
    }

    /** @return array<string, mixed> */
    public function build(Party $party, ?Guest $guest = null): array
    {
        $myVotes = null !== $guest ? $this->votes->findActiveSuggestionIdsForGuest($guest) : [];
        $myVotesLookup = array_fill_keys($myVotes, true);

        $ranking = [];
        foreach ($this->suggestions->findRanking($party) as $position => $suggestion) {
            $ranking[] = $this->serializeSuggestion($suggestion, $position + 1, $guest, $myVotesLookup);
        }

        $history = [];
        foreach ($this->suggestions->findRecentlyPlayed($party, 6) as $suggestion) {
            $history[] = $this->serializeSuggestion($suggestion, null, $guest, $myVotesLookup);
        }

        return [
            'party' => [
                'code' => $party->getCode(),
                'name' => $party->getName(),
                'active' => $party->isActive(),
                'votesPerGuest' => $party->getVotesPerGuest(),
            ],
            'sync' => [
                'status' => $party->getSyncStatus(),
                'message' => $party->getSyncMessage(),
                'at' => $party->getLastSyncAt()?->format(\DateTimeInterface::ATOM),
            ],
            'nowPlaying' => $party->getNowPlaying(),
            'ranking' => $ranking,
            'history' => $history,
            'guestCount' => $this->guests->countByParty($party),
            'me' => null === $guest ? null : [
                'nickname' => $guest->getNickname(),
                'votesLeft' => $this->voting->votesLeft($guest),
                'votedFor' => $myVotes,
            ],
        ];
    }

    /**
     * @param array<int, true> $myVotesLookup
     *
     * @return array<string, mixed>
     */
    private function serializeSuggestion(Suggestion $suggestion, ?int $position, ?Guest $guest, array $myVotesLookup): array
    {
        $id = (int) $suggestion->getId();

        return [
            'id' => $id,
            'position' => $position,
            'uri' => $suggestion->getSpotifyUri(),
            'title' => $suggestion->getTitle(),
            'artists' => $suggestion->getArtists(),
            'album' => $suggestion->getAlbum(),
            'imageUrl' => $suggestion->getImageUrl(),
            'durationMs' => $suggestion->getDurationMs(),
            'votes' => $suggestion->getVoteCount(),
            'status' => $suggestion->getStatus()->value,
            'suggestedBy' => $suggestion->getSuggestedBy()?->getLabel(),
            'mine' => null !== $guest && null !== $suggestion->getSuggestedBy()
                && $suggestion->getSuggestedBy()->getId() === $guest->getId(),
            'voted' => isset($myVotesLookup[$id]),
            'playedAt' => $suggestion->getPlayedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
