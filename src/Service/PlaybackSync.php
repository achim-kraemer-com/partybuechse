<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Party;
use App\Entity\Suggestion;
use App\Repository\PartyRepository;
use App\Repository\SuggestionRepository;
use App\Spotify\PlaybackState;
use App\Spotify\SpotifyApiException;
use App\Spotify\SpotifyApiInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Die Partybüchse ist der DJ: sie startet jeden Wunsch selbst.
 *
 * Bis 11/2026 legte sie den Top-Wunsch kurz vor dem Ende des laufenden Titels in Spotifys
 * Warteschlange. Das kann nicht zuverlässig funktionieren, denn diese Warteschlange gehört dem
 * Spotify-Konto und nicht der Party: Die Web-API kann nur hineinlegen, nie herausnehmen oder
 * umsortieren. Alles andere, was dort liegt – vom Host selbst eingereihte Titel, Autoplay,
 * Reste einer früheren Party –, läuft zwischen den Wünschen an, und wer gerade oben in der
 * Rangliste steht, entschied sich schon drei Minuten vorher. Genau das waren die „falschen
 * Lieder“.
 *
 * Jetzt wird nichts mehr eingereiht. Ein Durchgang:
 *  1. Playback-Status lesen und als „Läuft gerade“ speichern.
 *  2. Abschließen: Lief unser gestarteter Titel und läuft er nicht mehr, gilt er als gelaufen.
 *     Ist er noch nicht angelaufen, bekommt er STARTUP_SECONDS Zeit – und wenn er sie nicht
 *     nutzt, geht er zurück in die Rangliste, statt die Liste leerzuräumen. Hat der Host einen
 *     Wunsch von Hand gestartet, gilt er ab jetzt als der laufende.
 *  3. Ist der Player untätig oder der laufende Titel fast zu Ende, den bestbewerteten Wunsch
 *     direkt starten (PUT /me/player/play). Die Rangliste entscheidet im Moment des Wechsels.
 *  4. Keine Wünsche offen und Player untätig: Fallback-Playlist starten (falls konfiguriert).
 *
 * Der Preis sind die letzten Sekunden eines Titels: Die Büchse startet den nächsten Wunsch
 * TAKEOVER_MS vor dem Ende, weil Spotify sonst von selbst weiterspringt und ein fremder Titel
 * anfängt. Dafür entscheidet immer die Partybüchse, was als Nächstes läuft, und ein Wunsch kann
 * nicht mehr in einer anderen Party anlaufen.
 *
 * Die Musik des Hosts bleibt der natürliche Rückfall: Solange nichts gewünscht ist, fasst die
 * Büchse den Player nicht an (eine pausierte Wiedergabe ohnehin nie).
 */
class PlaybackSync
{
    /**
     * So lange vor dem Ende des laufenden Titels wird der nächste Wunsch gestartet.
     *
     * Muss über dem Abstand zweier Durchgänge liegen (MIN_INTERVAL_SECONDS), sonst fällt das
     * Fenster zwischen zwei Durchgänge und Spotify springt vorher selbst weiter.
     */
    public const TAKEOVER_MS = 3_000;

    /** Polling clients and the worker trigger a sync at most this often. */
    public const MIN_INTERVAL_SECONDS = 2;

    /**
     * So lange darf ein gestarteter Wunsch brauchen, bis er im Player auftaucht.
     *
     * Spotify quittiert PUT /me/player/play sofort, GET /me/player meldet danach aber noch eine
     * Weile den vorherigen Titel (oder gar keinen). Ohne dieses Fenster hielt der nächste
     * Durchgang den gerade gestarteten Wunsch für durchgelaufen, hakte ihn ab und startete den
     * nächsten – in wenigen Sekunden war die Liste leer und zu hören war nichts. Muss deutlich
     * über dem Abstand zweier Durchgänge liegen.
     */
    public const STARTUP_SECONDS = 8;

    /**
     * So oft darf ein Wunsch hintereinander nicht am Player ankommen, bevor Ruhe einkehrt.
     *
     * Ohne diese Grenze startete die Büchse denselben Wunsch alle zehn Sekunden neu, Nacht für
     * Nacht – bis Spotify das Kontingent der App sperrte (429, QUOTA_EXCEEDED). Dreimal darf
     * es eine Störung sein, danach ist es ein Zustand, den der Host beheben muss.
     */
    public const MAX_START_ATTEMPTS = 3;

    /** So lange bleibt der Player danach unangetastet. */
    public const TROUBLE_PAUSE_SECONDS = 300;

    /**
     * Abstand zweier Durchgänge, wenn es nichts zu tun gibt.
     *
     * Ohne offenen Wunsch und ohne laufenden Titel muss die Büchse Spotify nicht alle zwei
     * Sekunden fragen; eine Party, die über Nacht offen bleibt, kostete so Zehntausende
     * Aufrufe.
     */
    public const IDLE_INTERVAL_SECONDS = 30;

    public function __construct(
        private readonly SpotifyApiInterface $spotify,
        private readonly SuggestionRepository $suggestions,
        private readonly PartyRepository $parties,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Runs a sync unless one ran recently or is running right now. Safe to call from every poll.
     */
    public function syncIfDue(Party $party): ?SyncResult
    {
        if (!$party->isActive()) {
            return null;
        }

        if ($this->ranJustNow($party) || $party->isSpotifyQuiet($this->clock->now())) {
            return null;
        }

        return $this->locked($party, function () use ($party): ?SyncResult {
            // Noch einmal prüfen, diesmal im Lock: Host-Seite, Display, jedes Gast-Handy und der
            // Hintergrunddienst pollen unabhängig voneinander, mehrere passieren die Prüfung oben
            // gleichzeitig und stehen dann hintereinander hier an. Ohne diese zweite Prüfung lief
            // jeder von ihnen einen eigenen Durchgang – Sekundenbruchteile hintereinander, mit
            // einem Spotify-Zustand von vor dem Start.
            $this->entityManager->refresh($party);

            if ($this->ranJustNow($party) || $party->isSpotifyQuiet($this->clock->now())) {
                return null;
            }

            return $this->run($party);
        });
    }

    /** Liegt der letzte Durchgang dieser Party noch keinen vollen Abstand zurück? */
    private function ranJustNow(Party $party): bool
    {
        $last = $party->getLastSyncAt();

        return null !== $last
            && ($this->clock->now()->getTimestamp() - $last->getTimestamp()) < $this->interval($party);
    }

    /** Zwei Sekunden, solange etwas läuft oder etwas gewünscht ist – sonst in Ruhe. */
    private function interval(Party $party): int
    {
        $nothingPlaying = null === ($party->getNowPlaying()['track'] ?? null);

        return $nothingPlaying && null === $this->suggestions->findTopOpen($party)
            ? self::IDLE_INTERVAL_SECONDS
            : self::MIN_INTERVAL_SECONDS;
    }

    /** Ein Durchgang, auch wenn gerade erst einer war. */
    public function sync(Party $party): SyncResult
    {
        return $this->locked($party, fn (): SyncResult => $this->run($party))
            ?? new SyncResult($party->getSyncStatus(), 'Der Abgleich läuft gerade.');
    }

    private function run(Party $party): SyncResult
    {
        $host = $party->getHost();
        $now = $this->clock->now();

        if (!$party->isActive()) {
            return new SyncResult(Party::SYNC_ENDED, 'Die Party ist beendet.');
        }

        // Ein Spotify-Konto hat genau einen Player, also steuert ihn genau eine Party. Eine
        // neue Party beendet die laufende (HostController::create), hierher kommt also nur
        // noch, was vor dieser Regel entstanden ist: Hände weg vom Player.
        $current = $this->parties->findCurrentByHost($host);
        if (null !== $current && $current->getId() !== $party->getId()) {
            $party->setNowPlaying(null);
            $party->setSync(Party::SYNC_STANDBY, sprintf(
                'Die Musik läuft über die Party „%s“ (%s). Beende sie, wenn diese hier übernehmen soll.',
                $current->getName(),
                $current->getCode(),
            ), $now);
            $this->entityManager->flush();

            return new SyncResult(Party::SYNC_STANDBY, $party->getSyncMessage());
        }

        try {
            $state = $this->spotify->getPlaybackState($host);
        } catch (SpotifyApiException $e) {
            if (!$e->isNoActiveDevice()) {
                return $this->fail($party, $e, $now);
            }
            $state = null;
        }

        if (null === $state || null === $state->deviceId) {
            $party->setNowPlaying(null);
            $party->setSync(Party::SYNC_NO_DEVICE, 'Kein aktives Spotify-Gerät. Starte die Wiedergabe in Spotify, dann übernimmt die Partybüchse.', $now);
            $this->entityManager->flush();

            return new SyncResult(Party::SYNC_NO_DEVICE, $party->getSyncMessage());
        }

        $actions = [];

        try {
            $settlement = $this->settle($party, $state, $now);
            $actions = $settlement->actions;
            $this->snapshot($party, $state);

            if (null !== $settlement->problem) {
                // Der Startbefehl ist nicht angekommen. Jetzt den nächsten Wunsch zu starten
                // hieße, ihn genauso zu verheizen; der Host muss das Gerät wecken.
                $party->setSync(Party::SYNC_ERROR, $settlement->problem, $now);
                $this->entityManager->flush();
                $this->logger->info('Party sync', ['party' => $party->getCode(), 'actions' => $actions]);

                return new SyncResult(Party::SYNC_ERROR, $settlement->problem, $actions);
            }

            if (!$settlement->hold) {
                $actions = array_merge($actions, $this->play($party, $state, $now));
            }
        } catch (SpotifyApiException $e) {
            $this->entityManager->flush();

            return $this->fail($party, $e, $now, $actions);
        }

        $message = [] === $actions ? null : implode(' · ', $actions);
        $party->setSync(Party::SYNC_OK, $message, $now);
        $this->entityManager->flush();

        if ([] !== $actions) {
            $this->logger->info('Party sync', ['party' => $party->getCode(), 'actions' => $actions]);
        }

        return new SyncResult(Party::SYNC_OK, $message, $actions);
    }

    /**
     * „Weiter“ aus der Host-Ansicht: den laufenden Wunsch abhaken und sofort den nächsten starten.
     *
     * Ein blosses skipToNext würde Spotify entscheiden lassen, was als Nächstes kommt – und
     * damit wäre genau das zurück, was der DJ-Modus abschafft. Ohne offenen Wunsch bleibt es
     * beim Weiterspringen, dann gehört der Player wieder dem Host.
     */
    public function skipCurrent(Party $party): SyncResult
    {
        $now = $this->clock->now();

        if (!$party->isActive()) {
            return new SyncResult(Party::SYNC_ENDED, 'Die Party ist beendet.');
        }

        return $this->locked($party, function () use ($party, $now): SyncResult {
            // Der Host greift selbst ein: Zählwerk und Zwangspause beginnen von vorn.
            $party->clearPlaybackTrouble();

            try {
                $state = $this->spotify->getPlaybackState($party->getHost());
            } catch (SpotifyApiException $e) {
                if (!$e->isNoActiveDevice()) {
                    return $this->fail($party, $e, $now);
                }
                $state = null;
            }

            $top = $this->suggestions->findTopOpen($party);

            try {
                if (null !== $top) {
                    $actions = $this->start($party, $top, $now, 'Gestartet', $state?->deviceId);
                } else {
                    $this->closeRunning($party, $now);
                    $this->spotify->skipToNext($party->getHost(), $state?->deviceId);
                    $actions = ['Übersprungen'];
                }
            } catch (SpotifyApiException $e) {
                return $this->fail($party, $e, $now);
            }

            $message = implode(' · ', $actions);
            $party->setSync(Party::SYNC_OK, $message, $now);
            $this->entityManager->flush();

            return new SyncResult(Party::SYNC_OK, $message, $actions);
        }) ?? new SyncResult($party->getSyncStatus(), 'Der Abgleich läuft gerade, bitte noch einmal.');
    }

    /**
     * Bringt die Wunschliste mit dem Player in Übereinstimmung.
     *
     * Ein gestarteter Wunsch ist erst dann durchgelaufen, wenn er auch einmal gelaufen IST.
     * Spotify kennt kein „fertig“, nur das, was jetzt läuft – und meldet das verzögert.
     */
    private function settle(Party $party, PlaybackState $state, \DateTimeImmutable $now): Settlement
    {
        $actions = [];
        $current = $state->track;
        $running = $this->suggestions->findPlaying($party);

        // Unser Titel läuft: angekommen. Dieser Vermerk unterscheidet später „ist durch“ von
        // „ist nie angelaufen“.
        if (null !== $running && null !== $current && $running->getSpotifyUri() === $current->uri) {
            $running->confirm($now);
            $party->clearPlaybackTrouble();
            $this->entityManager->flush();

            return new Settlement();
        }

        if (null !== $running && null === $running->getConfirmedAt()) {
            // Gestartet, aber noch nie im Player gesehen.
            if ($this->isStarting($running, $now)) {
                return new Settlement(hold: true);
            }

            // Spotify hat den Start quittiert und trotzdem nichts gespielt: zurück in die
            // Rangliste. Ihn jetzt abzuhaken würde die Liste leerräumen, ohne dass ein einziger
            // Wunsch zu hören war.
            $running->reopen();
            $attempt = $party->countFailedStart();
            $this->logger->warning('The started wish never reached the player', [
                'party' => $party->getCode(),
                'wish' => $running->getSpotifyUri(),
                'device' => $state->deviceName,
                'isPlaying' => $state->isPlaying,
                'playerShows' => $state->track?->uri,
                'attempt' => $attempt,
            ]);

            $problem = 'Spotify hat den Titel nicht gestartet. Spiele in Spotify kurz etwas an, dann übernimmt die Partybüchse.';
            if ($attempt >= self::MAX_START_ATTEMPTS) {
                // Dreimal dasselbe ist keine Störung mehr. Weiter im Zehn-Sekunden-Takt zu
                // starten hiesse, das Spotify-Kontingent der App zu verbrennen.
                $party->quietenSpotify($now->modify(sprintf('+%d seconds', self::TROUBLE_PAUSE_SECONDS)));
                $problem = sprintf(
                    'Spotify nimmt keinen Titel an (%d Versuche). Die Partybüchse wartet %d Minuten. Spiele in Spotify etwas an oder wähle ein anderes Gerät.',
                    $attempt,
                    (int) (self::TROUBLE_PAUSE_SECONDS / 60),
                );
            }
            $this->entityManager->flush();

            return new Settlement(
                [sprintf('Start nicht angekommen: %s', $this->label($running))],
                problem: $problem,
            );
        }

        // Unser Titel lief und läuft jetzt nicht mehr: durch.
        if (null !== $running) {
            $running->markPlayed($now);
            $actions[] = sprintf('Gelaufen: %s', $this->label($running));
            $running = null;
        }

        // Hat der Host einen Wunsch in Spotify selbst gestartet, ist er ab jetzt der laufende.
        if (null === $running && null !== $current) {
            $wish = $this->suggestions->findPendingByUri($party, $current->uri);
            if (null !== $wish && $wish->isOpen()) {
                $wish->markPlaying($now);
                $wish->confirm($now);
                // Der Player spielt einen Wunsch: Was vorher schiefging, ist damit Geschichte.
                $party->clearPlaybackTrouble();
                $actions[] = sprintf('Läuft: %s', $this->label($wish));
            }
        }

        $this->entityManager->flush();

        return new Settlement($actions);
    }

    /** Läuft für diesen Wunsch noch das Anlauffenster? */
    private function isStarting(Suggestion $wish, \DateTimeImmutable $now): bool
    {
        $startedAt = $wish->getStartedAt();

        return null !== $startedAt
            && ($now->getTimestamp() - $startedAt->getTimestamp()) < self::STARTUP_SECONDS;
    }

    /** Speichert, was gerade läuft – samt dem Gast, der es sich gewünscht hat. */
    private function snapshot(Party $party, PlaybackState $state): void
    {
        $nowPlaying = $state->toArray();
        $nowPlaying['wishedBy'] = null;

        $current = $state->track;
        if (null !== $current) {
            $running = $this->suggestions->findPlaying($party);
            $wish = null !== $running && $running->getSpotifyUri() === $current->uri
                ? $running
                : $this->suggestions->findLastPlayedByUri($party, $current->uri);
            $nowPlaying['wishedBy'] = $wish?->getSuggestedBy()?->getLabel();
        }

        $party->setNowPlaying($nowPlaying);
    }

    /**
     * Startet den nächsten Wunsch, wenn es Zeit dafür ist, oder die Fallback-Playlist.
     *
     * @return string[]
     */
    private function play(Party $party, PlaybackState $state, \DateTimeImmutable $now): array
    {
        $current = $state->track;
        $remaining = $state->remainingMs();

        // Pausiert: Der Host hat die Musik angehalten. Dann hält sich die Büchse zurück.
        if (null !== $current && !$state->isPlaying && $state->progressMs > 0 && (null === $remaining || $remaining >= 1_500)) {
            return [];
        }

        $idle = null === $current || !$state->isPlaying;
        $top = $this->suggestions->findTopOpen($party);

        if (null === $top) {
            $fallback = $party->getFallbackPlaylistUri();
            if (null === $fallback) {
                return [];
            }

            // Auch am Ende des letzten Wunsches: Sonst sucht Spotify sich selbst aus, was
            // danach läuft (Autoplay mit „ähnlichen“ Titeln). Angefasst wird dabei nur ein
            // Titel, den die Büchse selbst gestartet hat – die Musik des Hosts bleibt ihre.
            $running = $this->suggestions->findPlaying($party);
            $ourTrackIsEnding = null !== $running && null !== $remaining && $remaining <= self::TAKEOVER_MS;

            if ($idle || $ourTrackIsEnding) {
                $this->closeRunning($party, $now);
                $this->spotify->startPlayback($party->getHost(), [], $fallback, $state->deviceId);

                return ['Keine Wünsche offen, Fallback-Playlist gestartet'];
            }

            return [];
        }

        if ($idle) {
            return $this->start($party, $top, $now, 'Gestartet', $state->deviceId);
        }

        if (null !== $remaining && $remaining <= self::TAKEOVER_MS) {
            return $this->start($party, $top, $now, 'Übernommen', $state->deviceId);
        }

        return [];
    }

    /**
     * Startet einen Wunsch auf dem Player des Hosts.
     *
     * Erst vermerken, dann starten: Geht der Schreibvorgang verloren, läuft der Titel, ohne
     * dass die Liste es weiss – und beim nächsten Durchgang würde er erneut von vorn beginnen.
     * Lehnt Spotify ab, geht der Wunsch zurück in die Rangliste.
     *
     * @return string[]
     */
    private function start(Party $party, Suggestion $wish, \DateTimeImmutable $now, string $what, ?string $deviceId): array
    {
        $this->closeRunning($party, $now);
        $wish->markPlaying($now);
        $this->entityManager->flush();

        try {
            $this->spotify->startPlayback($party->getHost(), [$wish->getSpotifyUri()], null, $deviceId);
        } catch (\Throwable $e) {
            // Zurück in die Rangliste. Läuft der Titel trotzdem (Zeitüberschreitung ohne
            // Antwort), merkt settle() das beim nächsten Durchgang am Player und bucht ihn
            // wieder als den laufenden – so oder so entscheidet die Wirklichkeit.
            $wish->reopen();
            $this->entityManager->flush();
            $this->logger->warning('Spotify refused to start the wish, it is back in the ranking', [
                'party' => $party->getCode(),
                'wish' => $wish->getSpotifyUri(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $this->logger->info('Wish started', [
            'party' => $party->getCode(),
            'what' => $what,
            'wish' => $wish->getSpotifyUri(),
        ]);

        return [sprintf('%s: %s', $what, $this->label($wish))];
    }

    /** Hakt ab, was die Büchse zuletzt gestartet hat. */
    private function closeRunning(Party $party, \DateTimeImmutable $now): void
    {
        foreach ($this->suggestions->findPlayingAll($party) as $running) {
            $running->markPlayed($now);
        }
    }

    /**
     * Führt den Aufruf aus, solange kein anderer Durchgang für diese Party läuft.
     *
     * Zwei gleichzeitige Durchgänge – etwa der Hintergrunddienst und ein Seitenaufruf – würden
     * beide denselben Wunsch starten und damit den Titel mitten im Lied neu beginnen.
     *
     * @param callable(): ?SyncResult $call
     */
    private function locked(Party $party, callable $call): ?SyncResult
    {
        $lock = $this->lockFactory->createLock('party-sync-'.$party->getId(), 30.0, false);
        if (!$lock->acquire()) {
            return null;
        }

        try {
            return $call();
        } finally {
            $lock->release();
        }
    }

    /** @param string[] $actions */
    private function fail(Party $party, SpotifyApiException $e, \DateTimeImmutable $now, array $actions = []): SyncResult
    {
        $this->logger->warning('Party sync failed', ['party' => $party->getCode(), 'error' => $e->getMessage(), 'status' => $e->statusCode]);

        // Spotify sagt bei einer Drosselung selbst, wie lange es Ruhe haben will.
        if ($e->isRateLimited()) {
            $party->quietenSpotify($now->modify(sprintf(
                '+%d seconds',
                $e->retryAfter ?? SpotifyApiException::DEFAULT_RETRY_AFTER,
            )));
        }
        $party->setSync(Party::SYNC_ERROR, $e->userMessage(), $now);
        $this->entityManager->flush();

        return new SyncResult(Party::SYNC_ERROR, $e->userMessage(), $actions);
    }

    private function label(Suggestion $suggestion): string
    {
        return sprintf('%s – %s', $suggestion->getTitle(), $suggestion->getArtists());
    }
}
