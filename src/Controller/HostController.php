<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Host;
use App\Entity\Party;
use App\Repository\PartyRepository;
use App\Repository\SuggestionRepository;
use App\Service\HostSession;
use App\Service\PartyCodeGenerator;
use App\Service\PartyStateBuilder;
use App\Service\PlaybackSync;
use App\Service\PublicUrlResolver;
use App\Service\SpotifyUri;
use App\Service\VotingService;
use App\Spotify\SpotifyApiException;
use App\Spotify\SpotifyApiInterface;
use App\Spotify\SpotifyAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Everything the host does: connect Spotify, create parties, tweak settings, moderate wishes, skip tracks.
 * The whole area needs ROLE_HOST (form login, see security.yaml); the firewall redirects anonymous visitors.
 */
#[Route('/host')]
#[IsGranted('ROLE_HOST')]
class HostController extends AbstractController
{
    public function __construct(
        private readonly HostSession $hostSession,
        private readonly PartyRepository $parties,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('', name: 'host_dashboard', methods: ['GET'])]
    public function dashboard(SpotifyAuthenticator $authenticator): Response
    {
        $host = $this->hostSession->getHost();

        return $this->render('host/dashboard.html.twig', [
            'host' => $host,
            'parties' => $host?->getParties() ?? [],
            'spotify_configured' => $authenticator->isConfigured(),
        ]);
    }

    #[Route('/party', name: 'host_party_create', methods: ['POST'])]
    public function create(Request $request, PartyCodeGenerator $codes): Response
    {
        if (null === $host = $this->hostSession->getHost()) {
            return $this->spotifyRequired();
        }
        if (!$this->isCsrfTokenValid('host_party_create', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Das Formular ist abgelaufen, bitte noch einmal absenden.');

            return $this->redirectToRoute('host_dashboard');
        }

        $name = trim((string) $request->request->get('name', ''));
        if ('' === $name) {
            $name = 'Party vom '.$this->clock->now()->format('d.m.Y');
        }
        $name = mb_substr($name, 0, 80);

        $party = new Party($host, $codes->generateCode(), $codes->generateDisplayKey(), $name, $this->clock->now());
        $party->setVotesPerGuest($request->request->getInt('votes_per_guest', 3));
        $party->setFallbackPlaylistUri(SpotifyUri::normalizePlaylist($request->request->get('fallback_playlist')));

        $this->entityManager->persist($party);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Party „%s“ angelegt. Code: %s', $party->getName(), $party->getCode()));

        return $this->redirectToRoute('host_party', ['code' => $party->getCode()]);
    }

    #[Route('/party/{code}', name: 'host_party', methods: ['GET'])]
    public function party(string $code, PartyStateBuilder $stateBuilder, PublicUrlResolver $urls, SuggestionRepository $suggestions): Response
    {
        if (null === $host = $this->hostSession->getHost()) {
            return $this->spotifyRequired();
        }
        $party = $this->ownParty($host, $code);

        return $this->render('host/party.html.twig', [
            'host' => $host,
            'party' => $party,
            'state' => $stateBuilder->build($party),
            'join_url' => $urls->joinUrl($party),
            'display_url' => $urls->displayUrl($party),
            'public_url_configured' => $urls->isConfigured(),
            'played_count' => \count($suggestions->findRecentlyPlayed($party, 1000)),
        ]);
    }

    #[Route('/party/{code}/settings', name: 'host_party_settings', methods: ['POST'])]
    public function settings(string $code, Request $request): Response
    {
        if (null === $host = $this->hostSession->getHost()) {
            return $this->spotifyRequired();
        }
        $party = $this->ownParty($host, $code);

        if (!$this->isCsrfTokenValid('host_party_settings', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Das Formular ist abgelaufen, bitte noch einmal absenden.');

            return $this->redirectToRoute('host_party', ['code' => $party->getCode()]);
        }

        $name = trim((string) $request->request->get('name', ''));
        if ('' !== $name) {
            $party->setName(mb_substr($name, 0, 80));
        }
        $party->setVotesPerGuest($request->request->getInt('votes_per_guest', $party->getVotesPerGuest()));

        $playlistInput = (string) $request->request->get('fallback_playlist', '');
        $playlist = SpotifyUri::normalizePlaylist($playlistInput);
        if ('' !== trim($playlistInput) && null === $playlist) {
            $this->addFlash('error', 'Die Fallback-Playlist wurde nicht erkannt. Füge einen Spotify-Link oder eine URI wie spotify:playlist:… ein.');
        } else {
            $party->setFallbackPlaylistUri($playlist);
        }

        $this->entityManager->flush();
        $this->addFlash('success', 'Einstellungen gespeichert.');

        return $this->redirectToRoute('host_party', ['code' => $party->getCode()]);
    }

    #[Route('/party/{code}/skip', name: 'host_party_skip', methods: ['POST'])]
    public function skip(string $code, Request $request, SpotifyApiInterface $spotify, PlaybackSync $sync): Response
    {
        if (null === $host = $this->hostSession->getHost()) {
            return $this->spotifyRequired();
        }
        $party = $this->ownParty($host, $code);

        if ($this->isCsrfTokenValid('host_party_action', (string) $request->request->get('_token'))) {
            try {
                $spotify->skipToNext($host);
                usleep(600_000); // give Spotify a moment before reading the new state
                $sync->sync($party);
                $this->addFlash('success', 'Übersprungen.');
            } catch (SpotifyApiException $e) {
                $this->addFlash('error', $e->userMessage());
            }
        }

        return $this->redirectToRoute('host_party', ['code' => $party->getCode()]);
    }

    #[Route('/party/{code}/suggestion/{id}/remove', name: 'host_suggestion_remove', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function removeSuggestion(string $code, int $id, Request $request, SuggestionRepository $suggestions, VotingService $voting): Response
    {
        if (null === $host = $this->hostSession->getHost()) {
            return $this->spotifyRequired();
        }
        $party = $this->ownParty($host, $code);

        if ($this->isCsrfTokenValid('host_party_action', (string) $request->request->get('_token'))) {
            $suggestion = $suggestions->find($id);
            if (null === $suggestion || $suggestion->getParty()->getId() !== $party->getId()) {
                throw $this->createNotFoundException();
            }
            $voting->remove($suggestion);
            $this->addFlash('success', sprintf('„%s“ wurde aus der Liste genommen.', $suggestion->getTitle()));
        }

        return $this->redirectToRoute('host_party', ['code' => $party->getCode()]);
    }

    #[Route('/party/{code}/end', name: 'host_party_end', methods: ['POST'])]
    public function end(string $code, Request $request): Response
    {
        if (null === $host = $this->hostSession->getHost()) {
            return $this->spotifyRequired();
        }
        $party = $this->ownParty($host, $code);

        if ($this->isCsrfTokenValid('host_party_action', (string) $request->request->get('_token'))) {
            $party->end($this->clock->now());
            $this->entityManager->flush();
            $this->addFlash('success', 'Party beendet. Gäste können nichts mehr wünschen; Spotify spielt normal weiter.');
        }

        return $this->redirectToRoute('host_party', ['code' => $party->getCode()]);
    }

    #[Route('/party/{code}/reopen', name: 'host_party_reopen', methods: ['POST'])]
    public function reopen(string $code, Request $request): Response
    {
        if (null === $host = $this->hostSession->getHost()) {
            return $this->spotifyRequired();
        }
        $party = $this->ownParty($host, $code);

        if ($this->isCsrfTokenValid('host_party_action', (string) $request->request->get('_token'))) {
            $party->reopen();
            $this->entityManager->flush();
            $this->addFlash('success', 'Party wieder geöffnet.');
        }

        return $this->redirectToRoute('host_party', ['code' => $party->getCode()]);
    }

    #[Route('/party/{code}/sync', name: 'host_party_sync', methods: ['POST'])]
    public function forceSync(string $code, Request $request, PlaybackSync $sync): Response
    {
        if (null === $host = $this->hostSession->getHost()) {
            return $this->spotifyRequired();
        }
        $party = $this->ownParty($host, $code);

        if ($this->isCsrfTokenValid('host_party_action', (string) $request->request->get('_token'))) {
            $result = $sync->sync($party);
            $this->addFlash(Party::SYNC_ERROR === $result->status ? 'error' : 'success', $result->message ?? 'Abgleich mit Spotify erledigt, nichts zu tun.');
        }

        return $this->redirectToRoute('host_party', ['code' => $party->getCode()]);
    }

    private function spotifyRequired(): RedirectResponse
    {
        $this->addFlash('error', 'Bitte verbinde zuerst dein Spotify-Konto.');

        return $this->redirectToRoute('host_dashboard');
    }

    private function ownParty(Host $host, string $code): Party
    {
        $party = $this->parties->findOneByCode($code);
        if (null === $party || $party->getHost()->getId() !== $host->getId()) {
            throw $this->createNotFoundException('Party nicht gefunden.');
        }

        return $party;
    }
}
