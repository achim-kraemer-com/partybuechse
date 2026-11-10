<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Party;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Löscht eine Party mit allem, was an ihr hängt.
 *
 * In den Entitäten ist kein cascade eingetragen, also wird hier ausdrücklich aufgeräumt:
 * Stimmen zeigen auf Wünsche und Gäste, Wünsche und Gäste zeigen auf die Party. Die
 * Reihenfolge der tatsächlichen DELETEs bestimmt Doctrine selbst aus diesen Beziehungen.
 *
 * Eine Stimme gehört immer zu einem Wunsch derselben Party – VotingService::vote() prüft das
 * mit assertSameParty() –, über die Wünsche ist also jede Stimme erfasst.
 */
class PartyDeleter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function delete(Party $party): void
    {
        foreach ($party->getSuggestions() as $suggestion) {
            foreach ($suggestion->getVotes() as $vote) {
                $this->entityManager->remove($vote);
            }
            $this->entityManager->remove($suggestion);
        }

        foreach ($party->getGuests() as $guest) {
            $this->entityManager->remove($guest);
        }

        $this->entityManager->remove($party);
        $this->entityManager->flush();
    }
}
