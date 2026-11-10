<?php

declare(strict_types=1);

namespace App\Tests\Spotify;

use App\Entity\Host;
use App\Spotify\HostTokenProvider;
use App\Spotify\SpotifyApiException;
use App\Spotify\SpotifyClient;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SpotifyClientTest extends TestCase
{
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-09 21:00:00');
    }

    /**
     * Der Fall aus dem Prod-Log: Spotify nimmt den Befehl an und antwortet mit 200, aber mit
     * einem Koerper, der kein JSON ist. Das darf den Start nicht abbrechen – es tat es, und
     * damit fiel der Wunsch aus der Liste, obwohl Spotify ihn angenommen hatte.
     */
    public function testStartPlaybackAndSkipSurviveAResponseThatIsNotJson(): void
    {
        $client = $this->clientFor(
            new MockResponse('nicht JSON', ['http_code' => 200]),
            new MockResponse('nicht JSON', ['http_code' => 200]),
        );

        $client->startPlayback($this->host(), ['spotify:track:'.str_repeat('a', 22)]);
        $client->skipToNext($this->host());

        $this->expectNotToPerformAssertions();
    }

    public function testAWriteStillFailsOnAnErrorStatus(): void
    {
        $client = $this->clientFor(new MockResponse(
            '{"error":{"status":404,"message":"Player command failed: No active device found","reason":"NO_ACTIVE_DEVICE"}}',
            ['http_code' => 404],
        ));

        $this->expectException(SpotifyApiException::class);
        $this->expectExceptionMessage('No active device found');

        $client->startPlayback($this->host(), ['spotify:track:'.str_repeat('a', 22)]);
    }

    /**
     * Beim Lesen bleibt ein unlesbarer Koerper ein Fehler – dort brauchen wir die Daten. Die
     * Meldung nennt jetzt den Anfang des Koerpers, sonst steht im Log nur, dass etwas unlesbar war.
     */
    public function testAReadFailsOnAnUnreadableBodyAndSaysWhatArrived(): void
    {
        $client = $this->clientFor(new MockResponse('<html>Gateway Timeout</html>', ['http_code' => 200]));

        try {
            $client->getPlaybackState($this->host());
            self::fail('Ein unlesbarer Koerper muss beim Lesen eine Ausnahme ergeben.');
        } catch (SpotifyApiException $e) {
            self::assertStringContainsString('Unreadable response', $e->getMessage());
            self::assertStringContainsString('Gateway Timeout', $e->getMessage());
        }
    }

    public function testNoActiveDeviceComesBackAsNullNotAsAnError(): void
    {
        $client = $this->clientFor(new MockResponse('', ['http_code' => 204]));

        self::assertNull($client->getPlaybackState($this->host()));
    }

    private function clientFor(MockResponse ...$responses): SpotifyClient
    {
        $httpClient = new MockHttpClient(array_values($responses));

        return new SpotifyClient($httpClient, $this->tokenProvider($httpClient), $this->clock, new NullLogger());
    }

    /** Der Host hat ein Token, das noch eine Stunde gilt: aktualisiert wird hier nichts. */
    private function tokenProvider(HttpClientInterface $httpClient): HostTokenProvider
    {
        return new HostTokenProvider(
            $httpClient,
            $this->createMock(EntityManagerInterface::class),
            $this->clock,
            new NullLogger(),
            'client-id',
            'client-secret',
        );
    }

    private function host(): Host
    {
        $now = $this->clock->now();

        return new Host('spotify-user-1', 'access-token', 'refresh-token', $now->modify('+1 hour'), $now);
    }
}
