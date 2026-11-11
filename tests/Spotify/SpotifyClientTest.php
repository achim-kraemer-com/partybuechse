<?php

declare(strict_types=1);

namespace App\Tests\Spotify;

use App\Entity\Host;
use App\Spotify\HostTokenProvider;
use App\Spotify\SpotifyApiException;
use App\Spotify\SpotifyClient;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
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

    /**
     * 204 heisst „ausgeführt“, alles andere darunter nur „angenommen“: 202 steht für ein Gerät,
     * das Spotify gerade nicht erreicht, und der Befehl verfällt still. Im Log stand bisher nur,
     * dass die Büchse gestartet hat – nie, was Spotify dazu gesagt hat.
     */
    public function testAPlayerCommandThatWasOnlyAcceptedIsLogged(): void
    {
        $log = $this->collectingLogger();
        $client = $this->clientWith($log, new MockResponse('', ['http_code' => 202]));

        $client->startPlayback($this->host(), ['spotify:track:'.str_repeat('a', 22)]);

        self::assertCount(1, $log->warnings());
        self::assertStringContainsString('without carrying it out', $log->warnings()[0]['message']);
        self::assertSame(202, $log->warnings()[0]['context']['status']);
        self::assertSame('/me/player/play', $log->warnings()[0]['context']['path']);
    }

    public function testAnExecutedPlayerCommandIsNotWorthALogLine(): void
    {
        $log = $this->collectingLogger();
        $client = $this->clientWith($log, new MockResponse('', ['http_code' => 204]));

        $client->startPlayback($this->host(), ['spotify:track:'.str_repeat('a', 22)]);

        self::assertSame([], $log->warnings(), 'Ein ausgefuehrter Befehl ist keine Warnung wert.');
    }

    /** Der von Spotify vorgesehene Weg, ein Gerät zu übernehmen: PUT /me/player. */
    public function testTransferSendsTheDeviceAndAsksForPlayback(): void
    {
        $seen = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['method' => $method, 'url' => $url, 'body' => $options['body'] ?? ''];

            return new MockResponse('', ['http_code' => 204]);
        });
        $client = new SpotifyClient($httpClient, $this->tokenProvider($httpClient), $this->clock, new NullLogger());

        $client->transferPlayback($this->host(), 'device-1');

        self::assertSame('PUT', $seen['method']);
        self::assertSame(SpotifyClient::API_BASE.'/me/player', $seen['url']);
        self::assertSame(['device_ids' => ['device-1'], 'play' => true], json_decode((string) $seen['body'], true));
    }

    public function testQueueingSendsTheUriAsAQueryParameter(): void
    {
        $seen = '';
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$seen): MockResponse {
            $seen = $method.' '.$url;

            return new MockResponse('', ['http_code' => 204]);
        });
        $client = new SpotifyClient($httpClient, $this->tokenProvider($httpClient), $this->clock, new NullLogger());

        $client->addToQueue($this->host(), 'spotify:track:'.str_repeat('a', 22), 'device-1');

        self::assertStringContainsString('POST '.SpotifyClient::API_BASE.'/me/player/queue', $seen);
        self::assertStringContainsString('uri=spotify:track:'.str_repeat('a', 22), $seen);
        self::assertStringContainsString('device_id=device-1', $seen);
    }

    /** Der Fall vom 11.11.: 200 statt 204, und in der Warteschlange landet nichts. */
    public function testAQueueCommandWithTheWrongStatusIsLoggedWithWhatCameBack(): void
    {
        $log = $this->collectingLogger();
        $client = $this->clientWith($log, new MockResponse('yIapJeGeBVsqLVs874nacau4cUU', ['http_code' => 200]));

        $client->addToQueue($this->host(), 'spotify:track:'.str_repeat('a', 22));

        self::assertSame(200, $log->warnings()[0]['context']['status']);
        self::assertSame('yIapJeGeBVsqLVs874nacau4cUU', $log->warnings()[0]['context']['body']);
    }

    public function testTheQueueIsReadAsTracks(): void
    {
        $client = $this->clientFor(new MockResponse(
            '{"currently_playing":null,"queue":[{"uri":"spotify:track:'.str_repeat('b', 22).'","name":"Zweiter","artists":[{"name":"Band"}],"duration_ms":1000}]}',
            ['http_code' => 200],
        ));

        $queue = $client->getQueue($this->host());

        self::assertCount(1, $queue);
        self::assertSame('Zweiter', $queue[0]->title);
        self::assertSame('Band', $queue[0]->artists);
    }

    private function clientFor(MockResponse ...$responses): SpotifyClient
    {
        return $this->clientWith(new NullLogger(), ...$responses);
    }

    private function clientWith(LoggerInterface $logger, MockResponse ...$responses): SpotifyClient
    {
        $httpClient = new MockHttpClient(array_values($responses));

        return new SpotifyClient($httpClient, $this->tokenProvider($httpClient), $this->clock, $logger);
    }

    /** Sammelt die Zeilen, die der Client schreiben würde. */
    private function collectingLogger(): LoggerInterface
    {
        return new class extends AbstractLogger {
            /** @var array<int, array{level: string, message: string, context: array<string, mixed>}> */
            public array $lines = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }

            /** @return array<int, array{level: string, message: string, context: array<string, mixed>}> */
            public function warnings(): array
            {
                return array_values(array_filter($this->lines, static fn (array $l): bool => 'warning' === $l['level']));
            }
        };
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
