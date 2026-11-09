<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\HostCredentials;
use App\Security\HostLoginAuthenticator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Login form for the host area. The POST is handled by HostLoginAuthenticator, the logout by the firewall.
 */
class LoginController extends AbstractController
{
    #[Route('/login', name: HostLoginAuthenticator::LOGIN_ROUTE, methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils, HostCredentials $credentials): Response
    {
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('host_dashboard');
        }

        return $this->render('security/login.html.twig', [
            'error_message' => $this->describe($authenticationUtils->getLastAuthenticationError()),
            'last_username' => $authenticationUtils->getLastUsername(),
            'configured' => $credentials->isConfigured(),
        ]);
    }

    #[Route('/logout', name: 'app_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('This route is intercepted by the logout key on the firewall.');
    }

    /** German messages without pulling in the translation component. */
    private function describe(?AuthenticationException $error): ?string
    {
        return match (true) {
            null === $error => null,
            $error instanceof TooManyLoginAttemptsAuthenticationException => 'Zu viele Fehlversuche. Bitte in ein paar Minuten noch einmal probieren.',
            $error instanceof InvalidCsrfTokenException => 'Das Formular ist abgelaufen. Bitte noch einmal absenden.',
            $error instanceof BadCredentialsException => 'Benutzername oder Passwort stimmt nicht.',
            default => 'Anmeldung fehlgeschlagen.',
        };
    }
}
