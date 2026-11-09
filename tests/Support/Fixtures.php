<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Guest;
use App\Entity\Host;
use App\Entity\Party;
use App\Spotify\TrackInfo;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tiny fixture helpers shared by the service tests.
 */
final class Fixtures
{
    public static function host(EntityManagerInterface $em, \DateTimeImmutable $now): Host
    {
        $host = new Host('spotify-user-1', 'access', 'refresh', $now->modify('+1 hour'), $now);
        $em->persist($host);
        $em->flush();

        return $host;
    }

    public static function party(EntityManagerInterface $em, Host $host, \DateTimeImmutable $now, int $votesPerGuest = 3): Party
    {
        $party = new Party($host, 'TEST1', str_repeat('a', 24), 'Testparty', $now);
        $party->setVotesPerGuest($votesPerGuest);
        $em->persist($party);
        $em->flush();

        return $party;
    }

    public static function guest(EntityManagerInterface $em, Party $party, \DateTimeImmutable $now, string $token, ?string $nickname = null): Guest
    {
        $guest = new Guest($party, $token, $now);
        $guest->setNickname($nickname);
        $em->persist($guest);
        $em->flush();

        return $guest;
    }

    public static function track(string $id, string $title, string $artist = 'Artist', int $durationMs = 200_000): TrackInfo
    {
        return new TrackInfo(
            uri: 'spotify:track:'.str_pad($id, 22, 'x'),
            title: $title,
            artists: $artist,
            album: 'Album',
            imageUrl: null,
            durationMs: $durationMs,
        );
    }
}
