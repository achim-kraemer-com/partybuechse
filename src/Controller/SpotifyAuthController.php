<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\HostSession;
use App\Spotify\SpotifyApiException;
use App\Spotify\SpotifyAuthenticator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;

/**
 * Connects the logged-in host with their Spotify account (Authorization Code flow).
 * Both routes require ROLE_HOST (see security.yaml), so only the host can start the OAuth dance.
 */
class SpotifyAuthController extends AbstractController
{
    public function __construct(
        private readonly SpotifyAuthenticator $authenticator,
        private readonly HostSession $hostSession,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/spotify/login', name: 'spotify_login', methods: ['GET'])]
    public function login(): Response
    {
        if (!$this->authenticator->isConfigured()) {
            $this->addFlash('error', 'Spotify ist noch nicht eingerichtet: SPOTIFY_CLIENT_ID und SPOTIFY_CLIENT_SECRET fehlen in .env.local.');

            return $this->redirectToRoute('host_dashboard');
        }

        $state = $this->hostSession->issueOauthState();

        return $this->redirect($this->authenticator->getAuthorizeUrl($state));
    }

    #[Route('/spotify/callback', name: 'spotify_callback', methods: ['GET'])]
    public function callback(Request $request): Response
    {
        if (!$this->hostSession->consumeOauthState($request->query->get('state'))) {
            $this->addFlash('error', 'Die Spotify-Anmeldung konnte nicht zugeordnet werden (ungültiger State). Bitte noch einmal versuchen.');

            return $this->redirectToRoute('host_dashboard');
        }

        if ($request->query->has('error')) {
            $this->addFlash('error', 'Spotify hat die Anmeldung abgelehnt: '.$request->query->get('error'));

            return $this->redirectToRoute('host_dashboard');
        }

        $code = (string) $request->query->get('code', '');
        if ('' === $code) {
            $this->addFlash('error', 'Spotify hat keinen Autorisierungscode geliefert.');

            return $this->redirectToRoute('host_dashboard');
        }

        try {
            $host = $this->authenticator->completeLogin($code);
        } catch (SpotifyApiException $e) {
            $this->logger->error('Spotify login failed', ['error' => $e->getMessage(), 'status' => $e->statusCode]);
            $hint = 403 === $e->statusCode
                ? ' Prüfe im Spotify Developer Dashboard, ob dein Konto der App zugeordnet ist (Development Mode).'
                : ' Prüfe Client-ID, Client-Secret und die Redirect-URI ('.$this->authenticator->getRedirectUri().').';
            $this->addFlash('error', 'Spotify-Anmeldung fehlgeschlagen: '.$e->getMessage().$hint);

            return $this->redirectToRoute('host_dashboard');
        } catch (HttpClientException $e) {
            $this->logger->error('Spotify login transport error', ['error' => $e->getMessage()]);
            $this->addFlash('error', 'Spotify ist gerade nicht erreichbar: '.$e->getMessage());

            return $this->redirectToRoute('host_dashboard');
        }

        $this->hostSession->useHost($host);
        $this->addFlash('success', sprintf('Spotify verbunden als %s.', $host->getDisplayName() ?? $host->getSpotifyUserId()));

        return $this->redirectToRoute('host_dashboard');
    }
}
