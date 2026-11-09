<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Party;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds the URL guests scan. Uses PUBLIC_BASE_URL (e.g. the LAN address) when set,
 * otherwise the host of the current request.
 */
class PublicUrlResolver
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ?string $publicBaseUrl,
    ) {
    }

    public function joinUrl(Party $party): string
    {
        $path = $this->urlGenerator->generate('guest_party', ['code' => $party->getCode()]);

        return $this->baseUrl().$path;
    }

    public function displayUrl(Party $party): string
    {
        $path = $this->urlGenerator->generate('display', ['code' => $party->getCode(), 'key' => $party->getDisplayKey()]);

        return $this->baseUrl().$path;
    }

    public function baseUrl(): string
    {
        $configured = trim((string) $this->publicBaseUrl);
        if ('' !== $configured) {
            return rtrim($configured, '/');
        }

        $request = $this->requestStack->getMainRequest();
        if (null !== $request) {
            return $request->getSchemeAndHttpHost().rtrim($request->getBaseUrl(), '/');
        }

        return rtrim($this->urlGenerator->getContext()->getScheme().'://'.$this->urlGenerator->getContext()->getHost(), '/');
    }

    public function isConfigured(): bool
    {
        return '' !== trim((string) $this->publicBaseUrl);
    }
}
