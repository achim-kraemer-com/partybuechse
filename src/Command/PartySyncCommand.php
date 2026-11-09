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
 * Optional background worker. Not required: every page poll already triggers a sync.
 * Useful when nobody keeps a browser open, e.g. for a headless Raspberry Pi setup.
 */
#[AsCommand(name: 'app:party:sync', description: 'Keeps active parties in sync with Spotify (loop with --watch).')]
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
            ->addOption('watch', 'w', InputOption::VALUE_NONE, 'Keep running and sync every few seconds')
            ->addOption('interval', 'i', InputOption::VALUE_REQUIRED, 'Seconds between runs in watch mode', '3');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $watch = (bool) $input->getOption('watch');
        $interval = max(2, (int) $input->getOption('interval'));

        do {
            $parties = $this->parties->findActive();
            if ([] === $parties && !$watch) {
                $io->note('Keine aktive Party.');
            }

            foreach ($parties as $party) {
                $result = $this->sync->sync($party);
                $line = sprintf('[%s] %s: %s', date('H:i:s'), $party->getCode(), $result->status);
                if (null !== $result->message) {
                    $line .= ' – '.$result->message;
                }
                if (Party::SYNC_ERROR === $result->status) {
                    $io->warning($line);
                } else {
                    $io->writeln($line);
                }
            }

            $this->entityManager->clear();

            if ($watch) {
                sleep($interval);
            }
        } while ($watch);

        return Command::SUCCESS;
    }
}
