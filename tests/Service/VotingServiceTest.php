<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Guest;
use App\Entity\Party;
use App\Entity\SuggestionStatus;
use App\Repository\SuggestionRepository;
use App\Service\VotingException;
use App\Service\VotingService;
use App\Tests\Support\Fixtures;
use App\Tests\Support\SchemaTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class VotingServiceTest extends KernelTestCase
{
    use SchemaTrait;

    private EntityManagerInterface $em;
    private VotingService $voting;
    private SuggestionRepository $suggestions;
    private Party $party;
    private Guest $anna;
    private Guest $ben;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->resetSchema($this->em);

        $this->voting = $container->get(VotingService::class);
        $this->suggestions = $container->get(SuggestionRepository::class);

        $now = new \DateTimeImmutable('2026-11-09 20:00:00');
        $host = Fixtures::host($this->em, $now);
        $this->party = Fixtures::party($this->em, $host, $now, 3);
        $this->anna = Fixtures::guest($this->em, $this->party, $now, str_repeat('a', 48), 'Anna');
        $this->ben = Fixtures::guest($this->em, $this->party, $now, str_repeat('b', 48), 'Ben');
    }

    public function testAWishCostsOneVoteAndThreeIsTheLimit(): void
    {
        self::assertSame(3, $this->voting->votesLeft($this->anna));

        $this->voting->suggest($this->anna, Fixtures::track('1', 'One'));
        $this->voting->suggest($this->anna, Fixtures::track('2', 'Two'));
        $this->voting->suggest($this->anna, Fixtures::track('3', 'Three'));

        self::assertSame(0, $this->voting->votesLeft($this->anna));

        $this->expectException(VotingException::class);
        $this->voting->suggest($this->anna, Fixtures::track('4', 'Four'));
    }

    public function testVotingForOthersAndRankingOrder(): void
    {
        $one = $this->voting->suggest($this->anna, Fixtures::track('1', 'One'));
        $two = $this->voting->suggest($this->anna, Fixtures::track('2', 'Two'));

        $this->voting->vote($this->ben, $two);

        self::assertSame(1, $one->getVoteCount());
        self::assertSame(2, $two->getVoteCount());
        self::assertSame(2, $this->voting->votesLeft($this->ben));

        $ranking = $this->suggestions->findRanking($this->party);
        self::assertSame(['Two', 'One'], array_map(static fn ($s) => $s->getTitle(), $ranking));
        self::assertSame($two->getId(), $this->suggestions->findTopOpen($this->party)?->getId());
    }

    public function testDuplicateWishTurnsIntoAVote(): void
    {
        $one = $this->voting->suggest($this->anna, Fixtures::track('1', 'One'));
        $again = $this->voting->suggest($this->ben, Fixtures::track('1', 'One'));

        self::assertSame($one->getId(), $again->getId());
        self::assertSame(2, $one->getVoteCount());
        self::assertCount(1, $this->suggestions->findRanking($this->party));

        // and a third attempt by the same guest is idempotent
        $this->voting->suggest($this->ben, Fixtures::track('1', 'One'));
        self::assertSame(2, $one->getVoteCount());
        self::assertSame(2, $this->voting->votesLeft($this->ben));
    }

    public function testToggleVoteGivesTheVoteBack(): void
    {
        $one = $this->voting->suggest($this->anna, Fixtures::track('1', 'One'));

        self::assertTrue($this->voting->toggleVote($this->ben, $one));
        self::assertSame(2, $one->getVoteCount());
        self::assertSame(2, $this->voting->votesLeft($this->ben));

        self::assertFalse($this->voting->toggleVote($this->ben, $one));
        self::assertSame(1, $one->getVoteCount());
        self::assertSame(3, $this->voting->votesLeft($this->ben));
    }

    public function testPlayedAndRemovedWishesFreeTheVotes(): void
    {
        $one = $this->voting->suggest($this->anna, Fixtures::track('1', 'One'));
        $two = $this->voting->suggest($this->anna, Fixtures::track('2', 'Two'));
        $this->voting->suggest($this->anna, Fixtures::track('3', 'Three'));
        self::assertSame(0, $this->voting->votesLeft($this->anna));

        $one->markPlayed(new \DateTimeImmutable());
        $this->em->flush();
        self::assertSame(1, $this->voting->votesLeft($this->anna));

        $this->voting->remove($two);
        self::assertSame(SuggestionStatus::Removed, $two->getStatus());
        self::assertSame(2, $this->voting->votesLeft($this->anna));
        self::assertCount(1, $this->suggestions->findRanking($this->party));
    }

    public function testTheRunningWishCannotBeVotedOn(): void
    {
        $one = $this->voting->suggest($this->anna, Fixtures::track('1', 'One'));
        $one->markPlaying(new \DateTimeImmutable());
        $this->em->flush();

        $this->expectException(VotingException::class);
        $this->voting->vote($this->ben, $one);
    }

    public function testEndedPartyRejectsWishes(): void
    {
        $this->party->end(new \DateTimeImmutable());
        $this->em->flush();

        $this->expectException(VotingException::class);
        $this->voting->suggest($this->anna, Fixtures::track('1', 'One'));
    }
}
