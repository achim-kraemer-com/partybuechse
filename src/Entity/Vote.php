<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\VoteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One guest, one vote per suggestion.
 */
#[ORM\Entity(repositoryClass: VoteRepository::class)]
#[ORM\Table(name: 'vote')]
#[ORM\UniqueConstraint(name: 'uniq_vote_suggestion_guest', columns: ['suggestion_id', 'guest_id'])]
#[ORM\Index(name: 'idx_vote_guest', columns: ['guest_id'])]
class Vote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Suggestion::class, inversedBy: 'votes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Suggestion $suggestion;

    #[ORM\ManyToOne(targetEntity: Guest::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Guest $guest;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Suggestion $suggestion, Guest $guest, \DateTimeImmutable $createdAt)
    {
        $this->suggestion = $suggestion;
        $this->guest = $guest;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSuggestion(): Suggestion
    {
        return $this->suggestion;
    }

    public function getGuest(): Guest
    {
        return $this->guest;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
