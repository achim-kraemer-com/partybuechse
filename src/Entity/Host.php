<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\HostRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The person who connected their Spotify Premium account.
 *
 * Only the host authenticates against Spotify; guests never see Spotify.
 * All Spotify API calls for a party are made with the host's tokens.
 */
#[ORM\Entity(repositoryClass: HostRepository::class)]
#[ORM\Table(name: 'host')]
#[ORM\UniqueConstraint(name: 'uniq_host_spotify_user', columns: ['spotify_user_id'])]
class Host
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $spotifyUserId;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $displayName = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $accessToken;

    #[ORM\Column(type: Types::TEXT)]
    private string $refreshToken;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $tokenExpiresAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, Party> */
    #[ORM\OneToMany(targetEntity: Party::class, mappedBy: 'host')]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $parties;

    public function __construct(
        string $spotifyUserId,
        string $accessToken,
        string $refreshToken,
        \DateTimeImmutable $tokenExpiresAt,
        \DateTimeImmutable $createdAt,
    ) {
        $this->spotifyUserId = $spotifyUserId;
        $this->accessToken = $accessToken;
        $this->refreshToken = $refreshToken;
        $this->tokenExpiresAt = $tokenExpiresAt;
        $this->createdAt = $createdAt;
        $this->parties = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSpotifyUserId(): string
    {
        return $this->spotifyUserId;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(?string $displayName): self
    {
        $this->displayName = $displayName;

        return $this;
    }

    public function getAccessToken(): string
    {
        return $this->accessToken;
    }

    public function getRefreshToken(): string
    {
        return $this->refreshToken;
    }

    public function getTokenExpiresAt(): \DateTimeImmutable
    {
        return $this->tokenExpiresAt;
    }

    /**
     * Stores a fresh token set. Spotify may omit the refresh token on refresh; keep the old one then.
     */
    public function updateTokens(string $accessToken, ?string $refreshToken, \DateTimeImmutable $expiresAt): self
    {
        $this->accessToken = $accessToken;
        if (null !== $refreshToken && '' !== $refreshToken) {
            $this->refreshToken = $refreshToken;
        }
        $this->tokenExpiresAt = $expiresAt;

        return $this;
    }

    public function isTokenExpiringWithin(\DateTimeImmutable $now, int $seconds = 60): bool
    {
        return $this->tokenExpiresAt->getTimestamp() - $now->getTimestamp() <= $seconds;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, Party> */
    public function getParties(): Collection
    {
        return $this->parties;
    }
}
