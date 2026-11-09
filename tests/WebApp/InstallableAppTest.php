<?php

declare(strict_types=1);

namespace App\Tests\WebApp;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Die Seite soll sich in Chrome als App installieren lassen.
 *
 * Chrome verlangt dafür: HTTPS, ein Manifest mit Name, start_url, einem eigenständigen
 * Anzeigemodus und Icons in 192 und 512 Pixeln, dazu einen angemeldeten Service Worker.
 * Fehlt eines davon, verschwindet der Installieren-Knopf wortlos – deshalb diese Tests.
 */
final class InstallableAppTest extends WebTestCase
{
    private const PUBLIC_DIR = __DIR__.'/../../public';

    public function testTheManifestCarriesWhatChromeAsksFor(): void
    {
        $manifest = $this->manifest();

        self::assertNotSame('', (string) ($manifest['name'] ?? ''));
        self::assertNotSame('', (string) ($manifest['short_name'] ?? ''));
        self::assertSame('/', $manifest['start_url'] ?? null);
        self::assertSame('/', $manifest['scope'] ?? null);
        self::assertContains($manifest['display'] ?? null, ['standalone', 'fullscreen', 'minimal-ui']);
        self::assertArrayNotHasKey('prefer_related_applications', $manifest);
    }

    public function testBothIconSizesChromeNeedsArePresentAsPng(): void
    {
        $sizes = [];
        foreach ($this->manifest()['icons'] as $icon) {
            if ('image/png' === ($icon['type'] ?? null) && str_contains((string) ($icon['purpose'] ?? 'any'), 'any')) {
                $sizes[] = $icon['sizes'];
            }
        }

        self::assertContains('192x192', $sizes);
        self::assertContains('512x512', $sizes);
    }

    public function testAMaskableIconExistsSoAndroidDoesNotPutTheLogoInAWhiteCircle(): void
    {
        $maskable = array_filter(
            $this->manifest()['icons'],
            static fn (array $icon): bool => str_contains((string) ($icon['purpose'] ?? ''), 'maskable'),
        );

        self::assertNotEmpty($maskable);
    }

    public function testEveryIconFileExistsAndHasTheDeclaredSize(): void
    {
        foreach ($this->manifest()['icons'] as $icon) {
            $path = self::PUBLIC_DIR.$icon['src'];
            self::assertFileExists($path, sprintf('Im Manifest steht %s.', $icon['src']));

            if ('image/png' !== ($icon['type'] ?? null)) {
                continue;
            }

            [$width, $height] = $this->pngSize($path);
            self::assertSame(
                $icon['sizes'],
                sprintf('%dx%d', $width, $height),
                sprintf('%s ist tatsächlich %dx%d.', $icon['src'], $width, $height),
            );
        }
    }

    public function testEveryShortcutPointsIntoTheScope(): void
    {
        foreach ($this->manifest()['shortcuts'] ?? [] as $shortcut) {
            self::assertStringStartsWith('/', (string) $shortcut['url']);
            foreach ($shortcut['icons'] ?? [] as $icon) {
                self::assertFileExists(self::PUBLIC_DIR.$icon['src']);
            }
        }
    }

    /**
     * cache.addAll() ist alles oder nichts: fehlt eine einzige Datei, scheitert die Installation
     * des Service Workers – und ohne ihn bietet Chrome das Installieren nicht mehr an. Das
     * passiert lautlos, deshalb hier der Abgleich mit dem Dateisystem.
     */
    public function testTheServiceWorkerOnlyPrecachesFilesThatExist(): void
    {
        $sw = (string) file_get_contents(self::PUBLIC_DIR.'/sw.js');

        self::assertSame(1, preg_match("~const OFFLINE_URL = '([^']+)';~", $sw, $offline));
        self::assertSame(1, preg_match('~const SHELL = \[(.*?)\];~s', $sw, $shell));
        preg_match_all("~'([^']+)'~", $shell[1], $quoted);

        $paths = array_merge([$offline[1]], $quoted[1]);
        self::assertGreaterThan(1, \count($paths), 'Der Service Worker soll die Offline-Seite und ihr CSS vorhalten.');

        foreach ($paths as $path) {
            self::assertFileExists(self::PUBLIC_DIR.$path);
        }
    }

    public function testEveryPageLinksTheManifestAndRegistersTheServiceWorker(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('link[rel="manifest"]');
        self::assertSelectorExists('link[rel="apple-touch-icon"]');
        self::assertSelectorExists('meta[name="theme-color"]');
        self::assertSelectorExists('script[src*="register-sw.js"]');
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $manifest = json_decode(
            (string) file_get_contents(self::PUBLIC_DIR.'/manifest.webmanifest'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($manifest);

        return $manifest;
    }

    /** @return array{int, int} Breite und Höhe aus dem IHDR-Block, ohne GD-Erweiterung. */
    private function pngSize(string $path): array
    {
        $header = (string) file_get_contents($path, false, null, 0, 24);
        self::assertStringStartsWith("\x89PNG", $header, sprintf('%s ist keine PNG-Datei.', $path));

        /** @var array{width: int, height: int} $size */
        $size = unpack('Nwidth/Nheight', substr($header, 16, 8));

        return [$size['width'], $size['height']];
    }
}
