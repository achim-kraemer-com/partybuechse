<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Party;
use App\Repository\PartyRepository;
use App\Service\PlaybackSync;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Der Herzschlag der Party – und mit dem DJ-Modus keine Kür mehr, sondern Pflicht.
 *
 * Die Partybüchse startet jeden Titel selbst, kurz vor dem Ende des laufenden. Dieses Fenster
 * muss jemand treffen, sonst springt Spotify von sich aus weiter und spielt, was in seiner
 * Warteschlange liegt. Seiten im Browser stossen den Abgleich zwar an, aber nur solange sie
 * sichtbar sind: Ein gesperrtes Handy oder ein Tab im Hintergrund pollt nicht mehr.
 *
 * Darum gehört dieser Dienst in den Betrieb. Dauerhaft:
 *
 *     php bin/console app:party:sync --watch
 *
 * Oder, wo kein Dauerprozess läuft (Plesk, Shared Hosting), als Cron-Eintrag jede Minute –
 * --max-runtime hält den Durchlauf kürzer als den Abstand zweier Starts:
 *
 *     * * * * * cd /pfad/zur/app && php bin/console app:party:sync --watch --max-runtime=55
 *
 * Mehrere Läufe gleichzeitig sind unschädlich: PlaybackSync sperrt pro Party und lässt pro
 * MIN_INTERVAL_SECONDS nur einen Durchgang zu, ganz gleich, woher er kommt.
 */
#[AsCommand(name: 'app:party:sync', description: 'Hält die laufende Party mit Spotify in Schritt (Dauerbetrieb mit --watch).')]
class PartySyncCommand extends Command
{
    public function __construct(
        private readonly PartyRepository $parties,
        private readonly PlaybackSync $sync,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('watch', 'w', InputOption::VALUE_NONE, 'Weiterlaufen und alle paar Sekunden abgleichen')
            ->addOption('interval', 'i', InputOption::VALUE_REQUIRED, 'Sekunden zwischen zwei Durchgängen', '2')
            ->addOption('max-runtime', 'm', InputOption::VALUE_REQUIRED, 'Nach so vielen Sekunden beenden (0 = unbegrenzt)', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $watch = (bool) $input->getOption('watch');
        $interval = max(1, (int) $input->getOption('interval'));
        $maxRuntime = max(0, (int) $input->getOption('max-runtime'));
        $deadline = 0 === $maxRuntime ? null : microtime(true) + $maxRuntime;

        do {
            $parties = $this->parties->findActive();
            if ([] === $parties && !$watch) {
                $io->note('Keine aktive Party.');
            }

            foreach ($parties as $party) {
                // syncIfDue statt sync: hält den Abstand ein und respektiert die Sperre, auch
                // wenn gleichzeitig Seiten pollen oder ein zweiter Lauf unterwegs ist.
                $result = $watch ? $this->sync->syncIfDue($party) : $this->sync->sync($party);
                if (null === $result) {
                    continue;
                }

                $line = sprintf('[%s] %s: %s', date('H:i:s'), $party->getCode(), $result->status);
                if (null !== $result->message) {
                    $line .= ' – '.$result->message;
                }
                if (Party::SYNC_ERROR === $result->status) {
                    $io->warning($line);
                } elseif (null !== $result->message || $output->isVerbose()) {
                    $io->writeln($line);
                }
            }

            $this->entityManager->clear();

            if (!$watch) {
                break;
            }

            if (null !== $deadline && microtime(true) + $interval > $deadline) {
                break;
            }

            sleep($interval);
        } while (true);

        return Command::SUCCESS;
    }
}
