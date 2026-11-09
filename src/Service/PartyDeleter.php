<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Party;
use App\Entity\Suggestion;
use App\Repository\SuggestionRepository;
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
        private readonly SuggestionRepository $suggestions,
    ) {
    }

    /**
     * Wünsche, die beim Löschen noch in Spotifys Warteschlange liegen.
     *
     * Die Partybüchse kann sie dort nicht herausnehmen, und nach dem Löschen weiß sie auch
     * nicht mehr, dass sie von hier stammen – dann läuft so ein Titel irgendwann mitten in
     * einer anderen Party an, ohne dass ihn jemand überspringt. Deshalb vor dem Löschen
     * erfragen und dem Host sagen.
     *
     * @return Suggestion[]
     */
    public function wishesStillWithSpotify(Party $party): array
    {
        return $this->suggestions->findQueued($party);
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
