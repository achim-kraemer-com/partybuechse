<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PartyRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One party session: guests join by code, suggest tracks and vote.
 */
#[ORM\Entity(repositoryClass: PartyRepository::class)]
#[ORM\Table(name: 'party')]
#[ORM\UniqueConstraint(name: 'uniq_party_code', columns: ['code'])]
#[ORM\Index(name: 'idx_party_host', columns: ['host_id'])]
class Party
{
    public const SYNC_IDLE = 'idle';
    public const SYNC_OK = 'ok';
    public const SYNC_NO_DEVICE = 'no_device';
    public const SYNC_ERROR = 'error';
    public const SYNC_ENDED = 'ended';

    /**
     * Active, but another party of the same host is the one driving Spotify.
     *
     * A Spotify account has one player, so only one party can steer it. Two parties syncing
     * against the same account both claim the running track as their own and both push wishes
     * into the same queue - which is exactly what happened on 2026-11-09.
     */
    public const SYNC_STANDBY = 'standby';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Host::class, inversedBy: 'parties')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Host $host;

    /** Short join code shown to guests (part of the QR code URL). */
    #[ORM\Column(length: 8)]
    private string $code;

    /** Secret key for the big-screen display, so it can run on a TV without a host login. */
    #[ORM\Column(length: 32)]
    private string $displayKey;

    #[ORM\Column(length: 80)]
    private string $name;

    #[ORM\Column]
    private int $votesPerGuest = 3;

    #[ORM\Column]
    private bool $active = true;

    /** Optional playlist that is started when nothing is playing and no wish is pending. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fallbackPlaylistUri = null;

    /** Snapshot of the host's Spotify playback, refreshed by the PlaybackSync service. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $nowPlaying = null;

    #[ORM\Column(length: 32)]
    private string $syncStatus = self::SYNC_IDLE;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $syncMessage = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSyncAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    /** @var Collection<int, Guest> */
    #[ORM\OneToMany(targetEntity: Guest::class, mappedBy: 'party')]
    private Collection $guests;

    /** @var Collection<int, Suggestion> */
    #[ORM\OneToMany(targetEntity: Suggestion::class, mappedBy: 'party')]
    private Collection $suggestions;

    public function __construct(Host $host, string $code, string $displayKey, string $name, \DateTimeImmutable $createdAt)
    {
        $this->host = $host;
        $this->code = $code;
        $this->displayKey = $displayKey;
        $this->name = $name;
        $this->createdAt = $createdAt;
        $this->guests = new ArrayCollection();
        $this->suggestions = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHost(): Host
    {
        return $this->host;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getDisplayKey(): string
    {
        return $this->displayKey;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getVotesPerGuest(): int
    {
        return $this->votesPerGuest;
    }

    public function setVotesPerGuest(int $votesPerGuest): self
    {
        $this->votesPerGuest = max(1, min(10, $votesPerGuest));

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function end(\DateTimeImmutable $now): self
    {
        $this->active = false;
        $this->endedAt = $now;
        $this->syncStatus = self::SYNC_ENDED;
        $this->nowPlaying = null;

        return $this;
    }

    public function reopen(): self
    {
        $this->active = true;
        $this->endedAt = null;
        $this->syncStatus = self::SYNC_IDLE;

        return $this;
    }

    public function getFallbackPlaylistUri(): ?string
    {
        return $this->fallbackPlaylistUri;
    }

    public function setFallbackPlaylistUri(?string $fallbackPlaylistUri): self
    {
        $this->fallbackPlaylistUri = $fallbackPlaylistUri;

        return $this;
    }

    public function getNowPlaying(): ?array
    {
        return $this->nowPlaying;
    }

    public function setNowPlaying(?array $nowPlaying): self
    {
        $this->nowPlaying = $nowPlaying;

        return $this;
    }

    public function getSyncStatus(): string
    {
        return $this->syncStatus;
    }

    public function getSyncMessage(): ?string
    {
        return $this->syncMessage;
    }

    public function setSync(string $status, ?string $message, \DateTimeImmutable $at): self
    {
        $this->syncStatus = $status;
        $this->syncMessage = $message;
        $this->lastSyncAt = $at;

        return $this;
    }

    public function getLastSyncAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    /** @return Collection<int, Guest> */
    public function getGuests(): Collection
    {
        return $this->guests;
    }

    /** @return Collection<int, Suggestion> */
    public function getSuggestions(): Collection
    {
        return $this->suggestions;
    }
}
