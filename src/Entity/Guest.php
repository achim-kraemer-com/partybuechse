<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GuestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An anonymous party guest, identified by a random token stored in a browser cookie.
 */
#[ORM\Entity(repositoryClass: GuestRepository::class)]
#[ORM\Table(name: 'guest')]
#[ORM\UniqueConstraint(name: 'uniq_guest_party_token', columns: ['party_id', 'token'])]
class Guest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Party::class, inversedBy: 'guests')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Party $party;

    #[ORM\Column(length: 64)]
    private string $token;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $nickname = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Party $party, string $token, \DateTimeImmutable $createdAt)
    {
        $this->party = $party;
        $this->token = $token;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getParty(): Party
    {
        return $this->party;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getNickname(): ?string
    {
        return $this->nickname;
    }

    public function setNickname(?string $nickname): self
    {
        $nickname = null === $nickname ? null : trim($nickname);
        $this->nickname = ('' === $nickname) ? null : mb_substr((string) $nickname, 0, 40);

        return $this;
    }

    /** Name shown to other guests; falls back to a neutral label. */
    public function getLabel(): string
    {
        return $this->nickname ?? 'Gast';
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
