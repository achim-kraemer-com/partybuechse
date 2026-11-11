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
 *     php bin/console app:spotify:doctor              # nur lesen: Geräte und Wiedergabestand
 *     php bin/console app:spotify:doctor --play -v    # dazu den Top-Wunsch starten und nachsehen
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
            $io->text(sprintf('PUT /me/player/play mit %s auf Gerät %s', $uri, $before?->deviceName ?? '(keines)'));
            $this->spotify->startPlayback($host, [$uri], null, $before?->deviceId);
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
            $io->text('Prüfen: Spielt das Gerät oben gerade wirklich (Spotify Premium, Gerät aktiv, nicht "nur zuhörend")?');

            return Command::FAILURE;
        } catch (SpotifyApiException $e) {
            $io->error(sprintf('Spotify: %s (HTTP %s)', $e->getMessage(), $e->statusCode ?? '?'));

            return Command::FAILURE;
        }
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
