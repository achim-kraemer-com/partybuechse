/*
 * Service Worker der Partybüchse.
 *
 * Er ist dafür da, dass Chrome die Seite als App installieren kann, und dafür, dass eine
 * installierte App ohne Netz nicht auf die Dinosaurier-Seite fällt. Mehr nicht: die App ist
 * offline sinnlos, weil Wunschliste und Spotify auf dem Server liegen.
 *
 * Deshalb konsequent "erst das Netz, dann der Zwischenspeicher". Ein Service Worker, der
 * zuerst zwischenspeichert, liefert nach einem Deployment die alten CSS- und JS-Dateien aus,
 * und das fällt frühestens beim übernächsten Laden auf. Zwischengespeichert wird nur das
 * Nötigste für die Offline-Seite, und zwar beim Installieren.
 *
 * VERSION hochzählen, wenn sich eine der Dateien in SHELL ändert.
 */

const VERSION = 'partybuechse-shell-v2';
const OFFLINE_URL = '/offline.html';
const SHELL = [
    OFFLINE_URL,
    '/css/app.css',
    '/app-icons/icon-192.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(VERSION)
            .then((cache) => cache.addAll(SHELL))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((names) => Promise.all(names.filter((n) => n !== VERSION).map((n) => caches.delete(n))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    // Fremde Hosts (Google Fonts, Spotifys Cover) und die eigene API nie anfassen: die API
    // liefert den Live-Zustand der Party, ein zwischengespeicherter wäre schlimmer als keiner.
    if (url.origin !== self.location.origin || url.pathname.startsWith('/api/')) {
        return;
    }

    if ('navigate' === request.mode) {
        event.respondWith((async () => {
            try {
                return await fetch(request);
            } catch (error) {
                return (await caches.match(OFFLINE_URL)) || Response.error();
            }
        })());

        return;
    }

    event.respondWith((async () => {
        try {
            return await fetch(request);
        } catch (error) {
            const cached = await caches.match(request);
            if (cached) {
                return cached;
            }
            throw error;
        }
    })());
});
