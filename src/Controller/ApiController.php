<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Guest;
use App\Entity\Party;
use App\Repository\PartyRepository;
use App\Repository\SuggestionRepository;
use App\Service\GuestResolver;
use App\Service\PartyStateBuilder;
use App\Service\PlaybackSync;
use App\Service\TrackLookup;
use App\Service\VotingException;
use App\Service\VotingService;
use App\Spotify\SpotifyApiException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;

/**
 * JSON endpoints polled and called by the guest app, the host dashboard and the display.
 *
 * Every poll of /state also gives the PlaybackSync a chance to run, so the party keeps
 * going as long as anybody has a page open; no background worker is required.
 */
#[Route('/api/p/{code}', requirements: ['code' => '[A-Za-z0-9]{3,10}'])]
class ApiController extends AbstractController
{
    public function __construct(
        private readonly PartyRepository $parties,
        private readonly GuestResolver $guests,
        private readonly PartyStateBuilder $stateBuilder,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/state', name: 'api_state', methods: ['GET'])]
    public function state(string $code, Request $request, PlaybackSync $sync): JsonResponse
    {
        $party = $this->party($code);
        if (null === $party) {
            return $this->error('Party nicht gefunden.', Response::HTTP_NOT_FOUND);
        }

        try {
            $sync->syncIfDue($party);
        } catch (\Throwable $e) {
            // Never let a Spotify hiccup break the UI; the party state carries the last error.
            $this->logger->error('Sync crashed', ['error' => $e->getMessage(), 'party' => $party->getCode()]);
        }

        $guest = $this->guests->find($request, $party);

        return $this->json(['ok' => true] + $this->stateBuilder->build($party, $guest));
    }

    #[Route('/search', name: 'api_search', methods: ['GET'])]
    public function search(string $code, Request $request, TrackLookup $lookup, SuggestionRepository $suggestions): JsonResponse
    {
        $party = $this->party($code);
        if (null === $party) {
            return $this->error('Party nicht gefunden.', Response::HTTP_NOT_FOUND);
        }
        if (!$party->isActive()) {
            return $this->error('Die Party ist beendet.', Response::HTTP_CONFLICT);
        }

        $query = (string) $request->query->get('q', '');

        try {
            $tracks = $lookup->search($party->getHost(), $query);
        } catch (SpotifyApiException $e) {
            return $this->error($e->userMessage(), Response::HTTP_BAD_GATEWAY);
        } catch (HttpClientException $e) {
            return $this->error('Spotify ist gerade nicht erreichbar.', Response::HTTP_BAD_GATEWAY);
        }

        $pending = array_fill_keys($suggestions->findPendingUris($party), true);
        $results = [];
        foreach ($tracks as $track) {
            $results[] = $track->toArray() + ['pending' => isset($pending[$track->uri])];
        }

        return $this->json(['ok' => true, 'query' => $query, 'results' => $results]);
    }

    #[Route('/suggest', name: 'api_suggest', methods: ['POST'])]
    public function suggest(string $code, Request $request, TrackLookup $lookup, VotingService $voting): JsonResponse
    {
        $party = $this->party($code);
        if (null === $party) {
            return $this->error('Party nicht gefunden.', Response::HTTP_NOT_FOUND);
        }
        $guest = $this->guestOrNull($request, $party);
        if (null === $guest) {
            return $this->error('Bitte lade die Seite einmal neu.', Response::HTTP_FORBIDDEN);
        }

        $uri = (string) $request->getPayload()->get('uri', '');

        try {
            $track = $lookup->byUri($party->getHost(), $uri);
            if (null === $track) {
                return $this->error('Diesen Titel kennt Spotify nicht (mehr).', Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $suggestion = $voting->suggest($guest, $track);
        } catch (VotingException $e) {
            return $this->error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, $party, $guest);
        } catch (SpotifyApiException $e) {
            return $this->error($e->userMessage(), Response::HTTP_BAD_GATEWAY);
        } catch (HttpClientException) {
            return $this->error('Spotify ist gerade nicht erreichbar.', Response::HTTP_BAD_GATEWAY);
        }

        return $this->json([
            'ok' => true,
            'suggestionId' => $suggestion->getId(),
            'message' => sprintf('„%s“ steht auf der Liste.', $suggestion->getTitle()),
        ] + $this->stateBuilder->build($party, $guest));
    }

    #[Route('/vote/{id}', name: 'api_vote', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function vote(string $code, int $id, Request $request, SuggestionRepository $suggestions, VotingService $voting): JsonResponse
    {
        $party = $this->party($code);
        if (null === $party) {
            return $this->error('Party nicht gefunden.', Response::HTTP_NOT_FOUND);
        }
        $guest = $this->guestOrNull($request, $party);
        if (null === $guest) {
            return $this->error('Bitte lade die Seite einmal neu.', Response::HTTP_FORBIDDEN);
        }

        $suggestion = $suggestions->find($id);
        if (null === $suggestion || $suggestion->getParty()->getId() !== $party->getId()) {
            return $this->error('Diesen Wunsch gibt es nicht.', Response::HTTP_NOT_FOUND);
        }

        try {
            $voted = $voting->toggleVote($guest, $suggestion);
        } catch (VotingException $e) {
            return $this->error($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY, $party, $guest);
        }

        return $this->json(['ok' => true, 'voted' => $voted] + $this->stateBuilder->build($party, $guest));
    }

    #[Route('/nickname', name: 'api_nickname', methods: ['POST'])]
    public function nickname(string $code, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $party = $this->party($code);
        if (null === $party) {
            return $this->error('Party nicht gefunden.', Response::HTTP_NOT_FOUND);
        }
        $guest = $this->guestOrNull($request, $party);
        if (null === $guest) {
            return $this->error('Bitte lade die Seite einmal neu.', Response::HTTP_FORBIDDEN);
        }

        $nickname = strip_tags((string) $request->getPayload()->get('nickname', ''));
        $guest->setNickname($nickname);
        $entityManager->flush();

        return $this->json(['ok' => true, 'nickname' => $guest->getNickname()] + $this->stateBuilder->build($party, $guest));
    }

    private function party(string $code): ?Party
    {
        return $this->parties->findOneByCode($code);
    }

    private function guestOrNull(Request $request, Party $party): ?Guest
    {
        // Cheap CSRF guard: browsers do not send this header on plain form posts.
        if ('fetch' !== $request->headers->get('X-Requested-With')) {
            return null;
        }

        return $this->guests->find($request, $party);
    }

    private function error(string $message, int $status, ?Party $party = null, ?Guest $guest = null): JsonResponse
    {
        $payload = ['ok' => false, 'error' => $message];
        if (null !== $party) {
            $payload += $this->stateBuilder->build($party, $guest);
        }

        return $this->json($payload, $status);
    }
}
