<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SuggestionRepository;
use App\Spotify\TrackInfo;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A track somebody wished for. Carries a denormalised vote count for cheap ranking queries.
 */
#[ORM\Entity(repositoryClass: SuggestionRepository::class)]
#[ORM\Table(name: 'suggestion')]
#[ORM\Index(name: 'idx_suggestion_party_status', columns: ['party_id', 'status'])]
#[ORM\Index(name: 'idx_suggestion_suggested_by', columns: ['suggested_by_id'])]
class Suggestion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Party::class, inversedBy: 'suggestions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Party $party;

    #[ORM\ManyToOne(targetEntity: Guest::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Guest $suggestedBy;

    #[ORM\Column(length: 100)]
    private string $spotifyUri;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 255)]
    private string $artists;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $album;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $imageUrl;

    #[ORM\Column]
    private int $durationMs;

    #[ORM\Column]
    private int $voteCount = 0;

    #[ORM\Column(length: 16, enumType: SuggestionStatus::class)]
    private SuggestionStatus $status = SuggestionStatus::Open;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** When the Partybüchse (or the host) started this track. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $playedAt = null;

    /**
     * Wann der Player diesen Titel zum ersten Mal wirklich gespielt hat.
     *
     * Spotify quittiert einen Startbefehl sofort, spielt ihn aber nicht in jedem Fall: ein
     * schlafendes Gerät, ein Konto, das anderswo läuft. Erst dieser Vermerk unterscheidet
     * einen Wunsch, der durch ist, von einem, der nie angelaufen ist.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    /** @var Collection<int, Vote> */
    #[ORM\OneToMany(targetEntity: Vote::class, mappedBy: 'suggestion')]
    private Collection $votes;

    public function __construct(Party $party, ?Guest $suggestedBy, TrackInfo $track, \DateTimeImmutable $createdAt)
    {
        $this->party = $party;
        $this->suggestedBy = $suggestedBy;
        $this->spotifyUri = $track->uri;
        $this->title = mb_substr($track->title, 0, 255);
        $this->artists = mb_substr($track->artists, 0, 255);
        $this->album = null === $track->album ? null : mb_substr($track->album, 0, 255);
        $this->imageUrl = $track->imageUrl;
        $this->durationMs = $track->durationMs;
        $this->createdAt = $createdAt;
        $this->votes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getParty(): Party
    {
        return $this->party;
    }

    public function getSuggestedBy(): ?Guest
    {
        return $this->suggestedBy;
    }

    public function getSpotifyUri(): string
    {
        return $this->spotifyUri;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getArtists(): string
    {
        return $this->artists;
    }

    public function getAlbum(): ?string
    {
        return $this->album;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function getDurationMs(): int
    {
        return $this->durationMs;
    }

    public function getVoteCount(): int
    {
        return $this->voteCount;
    }

    public function incrementVoteCount(): self
    {
        ++$this->voteCount;

        return $this;
    }

    public function decrementVoteCount(): self
    {
        $this->voteCount = max(0, $this->voteCount - 1);

        return $this;
    }

    public function setVoteCount(int $voteCount): self
    {
        $this->voteCount = max(0, $voteCount);

        return $this;
    }

    public function getStatus(): SuggestionStatus
    {
        return $this->status;
    }

    public function isOpen(): bool
    {
        return SuggestionStatus::Open === $this->status;
    }

    public function isPlaying(): bool
    {
        return SuggestionStatus::Playing === $this->status;
    }

    /** This track is on the speakers right now. */
    public function markPlaying(\DateTimeImmutable $at): self
    {
        $this->status = SuggestionStatus::Playing;
        $this->startedAt = $at;
        $this->confirmedAt = null;

        return $this;
    }

    /** Der Player hat den Titel gespielt – ab jetzt ist er nicht mehr bloß gestartet. */
    public function confirm(\DateTimeImmutable $at): self
    {
        $this->confirmedAt ??= $at;

        return $this;
    }

    /** Puts a wish back into the ranking (Spotify refused to start it). */
    public function reopen(): self
    {
        $this->status = SuggestionStatus::Open;
        $this->startedAt = null;
        $this->confirmedAt = null;

        return $this;
    }

    public function markPlayed(\DateTimeImmutable $at): self
    {
        $this->status = SuggestionStatus::Played;
        $this->playedAt = $at;

        return $this;
    }

    public function markRemoved(): self
    {
        $this->status = SuggestionStatus::Removed;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getPlayedAt(): ?\DateTimeImmutable
    {
        return $this->playedAt;
    }

    public function getConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    /** @return Collection<int, Vote> */
    public function getVotes(): Collection
    {
        return $this->votes;
    }

    public function toTrackInfo(): TrackInfo
    {
        return new TrackInfo(
            uri: $this->spotifyUri,
            title: $this->title,
            artists: $this->artists,
            album: $this->album,
            imageUrl: $this->imageUrl,
            durationMs: $this->durationMs,
        );
    }
}
