/*
 * Meldet den Service Worker an. Ohne ihn bietet Chrome das Installieren als App nicht an.
 *
 * Der Service Worker muss im Wurzelverzeichnis liegen, sonst gilt er nur für sein eigenes
 * Unterverzeichnis – /js/sw.js würde für /host und /p/ABC12 nichts tun.
 */
(function () {
    'use strict';

    if (!('serviceWorker' in navigator)) {
        return;
    }

    window.addEventListener('load', function () {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function (error) {
            // Kein Grund, die Seite zu stören: ohne Service Worker läuft alles weiter,
            // nur das Installieren und die Offline-Seite fallen weg.
            console.warn('Service Worker nicht angemeldet:', error);
        });
    });
})();
