<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Guest;
use App\Entity\Party;
use App\Repository\GuestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * Maps the browser cookie to a Guest record of the given party, creating both on first contact.
 */
class GuestResolver
{
    public const COOKIE = 'pb_guest';
    private const COOKIE_DAYS = 30;

    public function __construct(
        private readonly GuestRepository $guests,
        private readonly EntityManagerInterface $entityManager,
        private readonly PartyCodeGenerator $codes,
        private readonly ClockInterface $clock,
    ) {
    }

    /** Returns the guest for an existing cookie, or null if the browser has none yet. */
    public function find(Request $request, Party $party): ?Guest
    {
        $token = $this->tokenFromRequest($request);
        if (null === $token) {
            return null;
        }

        return $this->guests->findOneByPartyAndToken($party, $token);
    }

    /**
     * Returns the guest for this browser, creating it if needed.
     *
     * @return array{0: Guest, 1: ?Cookie} the guest and a cookie to attach when a new token was issued
     */
    public function resolve(Request $request, Party $party): array
    {
        $token = $this->tokenFromRequest($request);
        $cookie = null;

        if (null === $token) {
            $token = $this->codes->generateGuestToken();
            $cookie = $this->createCookie($token, $request->isSecure());
        }

        $guest = $this->guests->findOneByPartyAndToken($party, $token);
        if (null === $guest) {
            $guest = new Guest($party, $token, $this->clock->now());
            $this->entityManager->persist($guest);
            $this->entityManager->flush();
        }

        return [$guest, $cookie];
    }

    private function tokenFromRequest(Request $request): ?string
    {
        $token = $request->cookies->get(self::COOKIE);
        if (!\is_string($token) || 1 !== preg_match('~^[a-f0-9]{32,64}$~', $token)) {
            return null;
        }

        return $token;
    }

    private function createCookie(string $token, bool $secure): Cookie
    {
        return Cookie::create(self::COOKIE)
            ->withValue($token)
            ->withExpires($this->clock->now()->modify(sprintf('+%d days', self::COOKIE_DAYS)))
            ->withPath('/')
            ->withSecure($secure)
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX);
    }
}
