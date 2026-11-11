<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Host;
use App\Repository\HostRepository;
use App\Repository\PartyRepository;
use App\Repository\SuggestionRepository;
use App\Spotify\PlaybackState;
use App\Spotify\SpotifyApiException;
use App\Spotify\SpotifyApiInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fragt Spotify, was die Partybüchse dort vorfindet – und ob ein Startbefehl ankommt.
 *
 * Gebaut für den Fall, der im Log nur als „Start nicht angekommen“ steht: Spotify quittiert
 * PUT /me/player/play, und gespielt wird trotzdem nichts. Aus dem Log allein ist nicht zu sehen,
 * welches Gerät gemeint war und was es ist – ein Lautsprecher, der über Spotify Connect nur
 * zuhört ("is_restricted"), nimmt Befehle an und führt sie nie aus.
 *
 *     php bin/console app:spotify:doctor                       # nur lesen: Geräte, Wiedergabestand
 *     php bin/console app:spotify:doctor --play -v             # Top-Wunsch starten und nachsehen
 *     php bin/console app:spotify:doctor --play --no-device    # ohne device_id
 *     php bin/console app:spotify:doctor --play --transfer     # erst übergeben, dann starten
 *     php bin/console app:spotify:doctor --play --queue        # einreihen statt starten
 *
 * Tokens stehen hier nie, nur ihre Laufzeit.
 */
#[AsCommand(
    name: 'app:spotify:doctor',
    description: 'Zeigt Geräte und Wiedergabestand des Spotify-Kontos und prüft, ob Startbefehle ankommen.',
)]
class SpotifyDoctorCommand extends Command
{
    /** So lange wird dem Player nach einem Startbefehl standardmässig Zeit gegeben. */
    private const WAIT_SECONDS = 4;

    public function __construct(
        private readonly SpotifyApiInterface $spotify,
        private readonly HostRepository $hosts,
        private readonly PartyRepository $parties,
        private readonly SuggestionRepository $suggestions,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'play',
            'p',
            InputOption::VALUE_OPTIONAL,
            'Einen Titel starten und nachsehen, ob er anläuft (ohne Wert: der Top-Wunsch der laufenden Party)',
            false,
        );
        $this->addOption(
            'no-device',
            null,
            InputOption::VALUE_NONE,
            'Den Startbefehl ohne device_id schicken (dann gilt „das aktive Gerät“)',
        );
        $this->addOption(
            'queue',
            null,
            InputOption::VALUE_NONE,
            'Statt zu starten einreihen (POST /me/player/queue) und die Warteschlange auslesen',
        );
        $this->addOption(
            'transfer',
            't',
            InputOption::VALUE_NONE,
            'Die Wiedergabe vor dem Start ausdrücklich an das Gerät übergeben (PUT /me/player)',
        );
        $this->addOption(
            'wait',
            'w',
            InputOption::VALUE_REQUIRED,
            'Sekunden, die der Player nach dem Startbefehl bekommt',
            (string) self::WAIT_SECONDS,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $party = $this->parties->findActive()[0] ?? null;
        $host = $party?->getHost() ?? $this->hosts->findLatest();
        if (null === $host) {
            $io->error('Kein Spotify-Konto verbunden.');

            return Command::FAILURE;
        }

        $io->section('Konto und Party');
        $io->definitionList(
            ['Spotify-Konto' => $host->getSpotifyUserId()],
            ['Token läuft ab' => $host->getTokenExpiresAt()->format('Y-m-d H:i:s T')],
            ['Laufende Party' => null === $party ? 'keine' : sprintf('%s (%s)', $party->getName(), $party->getCode())],
        );

        try {
            $this->showDevices($io, $host);
            $before = $this->showState($io, $host, 'Wiedergabestand');

            $play = $input->getOption('play');
            if (false === $play) {
                $io->comment('Nur gelesen. Mit --play startet die Diagnose einen Titel.');

                return Command::SUCCESS;
            }

            $uri = \is_string($play) && '' !== $play
                ? $play
                : (null === $party ? null : $this->suggestions->findTopOpen($party)?->getSpotifyUri());
            if (null === $uri) {
                $io->error('Kein Titel zum Starten: --play=spotify:track:… angeben.');

                return Command::FAILURE;
            }

            $wait = max(0, (int) $input->getOption('wait'));

            $io->section('Startbefehl');

            $deviceId = $input->getOption('no-device') ? null : $before?->deviceId;

            if ($input->getOption('queue')) {
                return $this->queueInstead($io, $host, $uri, $deviceId, $wait);
            }

            if ($input->getOption('transfer')) {
                if (null === $before?->deviceId) {
                    $io->error('Ohne Gerät gibt es nichts zu übergeben.');

                    return Command::FAILURE;
                }
                $io->text(sprintf('PUT /me/player (Übergabe an %s, play: true)', $before->deviceName ?? '(unbekannt)'));
                $this->spotify->transferPlayback($host, $before->deviceId);
                $io->text('Übergabe ohne Fehler angenommen.');
            }

            $io->text(sprintf(
                'PUT /me/player/play mit %s%s',
                $uri,
                null === $deviceId ? ' ohne device_id' : sprintf(' auf Gerät %s', $before?->deviceName ?? '(unbekannt)'),
            ));
            $this->spotify->startPlayback($host, [$uri], null, $deviceId);
            $io->text('Spotify hat den Befehl ohne Fehler angenommen.');

            if ($wait > 0) {
                sleep($wait);
            }
            $after = $this->showState($io, $host, sprintf('Wiedergabestand %d Sekunden später', $wait));

            if ($after?->track?->uri === $uri) {
                $io->success('Der Titel läuft. Startbefehle kommen auf diesem Gerät an.');

                return Command::SUCCESS;
            }

            $io->warning('Der Titel läuft nicht. Spotify hat den Befehl angenommen und nicht ausgeführt – genau der Fall aus dem Log.');
            $io->text('Varianten: --no-device (ohne device_id) und --transfer (erst übergeben, dann starten).');

            return Command::FAILURE;
        } catch (SpotifyApiException $e) {
            $io->error(sprintf('Spotify: %s (HTTP %s)', $e->getMessage(), $e->statusCode ?? '?'));

            return Command::FAILURE;
        }
    }

    /**
     * Die Gegenprobe: Reiht den Titel ein, statt ihn zu starten.
     *
     * Kommt er in der Warteschlange an, nimmt der Player Befehle entgegen – dann liegt es
     * ausschliesslich am „play“. Kommt er nicht an, verwirft Spotify jeden Schreibbefehl.
     */
    private function queueInstead(SymfonyStyle $io, Host $host, string $uri, ?string $deviceId, int $wait): int
    {
        $io->section('Einreihen');
        $io->text(sprintf('POST /me/player/queue mit %s%s', $uri, null === $deviceId ? ' ohne device_id' : ''));
        $this->spotify->addToQueue($host, $uri, $deviceId);
        $io->text('Spotify hat den Befehl ohne Fehler angenommen.');

        if ($wait > 0) {
            sleep($wait);
        }

        $queue = $this->spotify->getQueue($host);
        $io->section('Warteschlange');
        if ([] === $queue) {
            $io->text('(leer)');
        }
        foreach (\array_slice($queue, 0, 5) as $i => $track) {
            $io->text(sprintf('%d. %s – %s', $i + 1, $track->title, $track->artists));
        }

        foreach ($queue as $track) {
            if ($track->uri === $uri) {
                $io->success('Der Titel steht in der Warteschlange. Schreibbefehle kommen an.');

                return Command::SUCCESS;
            }
        }

        $io->warning('Der Titel steht nicht in der Warteschlange. Auch das Einreihen verpufft.');

        return Command::FAILURE;
    }

    private function showDevices(SymfonyStyle $io, Host $host): void
    {
        $devices = $this->spotify->getDevices($host);

        $io->section('Geräte');
        if ([] === $devices) {
            $io->warning('Spotify meldet kein einziges Gerät. Dann kann kein Startbefehl ankommen.');

            return;
        }

        $rows = [];
        foreach ($devices as $device) {
            $rows[] = [
                $device['name'],
                $device['type'],
                $device['is_active'] ? 'aktiv' : '–',
                $device['is_restricted'] ? 'nimmt keine Befehle an' : 'steuerbar',
                '…'.mb_substr($device['id'], -6),
            ];
        }
        $io->table(['Gerät', 'Art', 'Aktiv', 'Steuerbar', 'Kennung'], $rows);
    }

    private function showState(SymfonyStyle $io, Host $host, string $title): ?PlaybackState
    {
        $state = $this->spotify->getPlaybackState($host);

        $io->section($title);
        if (null === $state) {
            $io->warning('Kein aktives Gerät (Spotify antwortet mit 204).');

            return null;
        }

        $io->definitionList(
            ['Gerät' => $state->deviceName ?? '(unbekannt)'],
            ['Spielt' => $state->isPlaying ? 'ja' : 'nein'],
            ['Titel' => null === $state->track ? '(keiner)' : sprintf('%s – %s', $state->track->title, $state->track->artists)],
            ['URI' => $state->track?->uri ?? '–'],
            ['Position' => sprintf('%d s von %d s', (int) ($state->progressMs / 1000), (int) (($state->track?->durationMs ?? 0) / 1000))],
            ['Rest' => null === $state->remainingMs() ? '–' : sprintf('%d ms', $state->remainingMs())],
            ['Gelesen um' => $this->clock->now()->format('H:i:s')],
        );

        return $state;
    }
}
