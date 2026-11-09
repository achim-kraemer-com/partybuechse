<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Host;
use App\Repository\HostRepository;
use App\Security\HostUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Which Spotify account (Host entity) the logged-in host is working with.
 *
 * Login itself is handled by Symfony Security (see config/packages/security.yaml). This class only
 * links the authenticated host user to a connected Spotify account: the one chosen in this session,
 * or otherwise the most recently connected one, so a server restart never forces a new OAuth round.
 */
class HostSession
{
    private const KEY = 'partybuechse.host_id';
    private const STATE_KEY = 'partybuechse.oauth_state';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly HostRepository $hosts,
        private readonly Security $security,
    ) {
    }

    /** The connected Spotify account, or null when none is connected yet or nobody is logged in. */
    public function getHost(): ?Host
    {
        if (!$this->security->isGranted(HostUser::ROLE)) {
            return null;
        }

        $session = $this->requestStack->getSession();
        $id = $session->get(self::KEY);
        if (\is_int($id)) {
            $host = $this->hosts->find($id);
            if (null !== $host) {
                return $host;
            }
        }

        $host = $this->hosts->findLatest();
        if (null !== $host) {
            $session->set(self::KEY, $host->getId());
        }

        return $host;
    }

    /** Remembers which Spotify account to use after a (re)connect. */
    public function useHost(Host $host): void
    {
        $this->requestStack->getSession()->set(self::KEY, $host->getId());
    }

    /** Creates and stores the OAuth "state" nonce. */
    public function issueOauthState(): string
    {
        $state = bin2hex(random_bytes(16));
        $this->requestStack->getSession()->set(self::STATE_KEY, $state);

        return $state;
    }

    /** Validates and consumes the OAuth "state" nonce. */
    public function consumeOauthState(?string $state): bool
    {
        $session = $this->requestStack->getSession();
        $expected = $session->get(self::STATE_KEY);
        $session->remove(self::STATE_KEY);

        return \is_string($expected) && \is_string($state) && hash_equals($expected, $state);
    }
}
