/* Partybüchse – shared browser helpers (no build step, no dependencies). */
(function (window, document) {
    'use strict';

    const PB = {};

    /** Creates an element with attributes and children (strings become text nodes). */
    PB.h = function (tag, attrs, ...children) {
        const el = document.createElement(tag);
        if (attrs) {
            for (const [key, value] of Object.entries(attrs)) {
                if (value === null || value === undefined || value === false) continue;
                if (key === 'class') el.className = value;
                else if (key === 'dataset') Object.assign(el.dataset, value);
                else if (key.startsWith('on') && typeof value === 'function') el.addEventListener(key.slice(2), value);
                else if (key === 'html') el.innerHTML = value;
                else el.setAttribute(key, value === true ? '' : String(value));
            }
        }
        for (const child of children.flat()) {
            if (child === null || child === undefined || child === false) continue;
            el.appendChild(typeof child === 'string' || typeof child === 'number' ? document.createTextNode(String(child)) : child);
        }
        return el;
    };

    PB.icon = function (name) {
        const paths = {
            up: '<path d="M12 5 4 14h5v5h6v-5h5z" fill="currentColor"/>',
            note: '<path d="M9 18V6l11-2v12" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><circle cx="6" cy="18" r="3" fill="currentColor"/><circle cx="17" cy="16" r="3" fill="currentColor"/>',
            check: '<path d="m5 12 5 5 9-10" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>',
            trash: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',
            play: '<path d="M7 5v14l12-7z" fill="currentColor"/>',
            pause: '<path d="M7 5h4v14H7zM13 5h4v14h-4z" fill="currentColor"/>',
        };
        const el = PB.h('span', { class: 'icon', 'aria-hidden': 'true' });
        el.innerHTML = '<svg viewBox="0 0 24 24" width="100%" height="100%">' + (paths[name] || '') + '</svg>';
        return el;
    };

    PB.formatMs = function (ms) {
        ms = Math.max(0, Math.round(ms || 0));
        const total = Math.floor(ms / 1000);
        const m = Math.floor(total / 60);
        const s = total % 60;
        return m + ':' + String(s).padStart(2, '0');
    };

    PB.cover = function (url, alt, cls) {
        if (url) {
            return PB.h('img', { class: 'cover ' + (cls || ''), src: url, alt: alt || '', loading: 'lazy', width: 52, height: 52 });
        }
        return PB.h('div', { class: 'cover cover-placeholder ' + (cls || '') }, PB.icon('note'));
    };

    /** Small fetch wrapper that always speaks JSON and marks itself as a fetch request. */
    PB.api = async function (url, options) {
        options = options || {};
        const init = {
            method: options.method || 'GET',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' },
            credentials: 'same-origin',
        };
        if (options.body !== undefined) {
            init.headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(options.body);
        }
        let response;
        try {
            response = await fetch(url, init);
        } catch (e) {
            throw new Error('Keine Verbindung zum Server.');
        }
        let data = null;
        try { data = await response.json(); } catch (e) { /* no body */ }
        if (!response.ok || !data || data.ok === false) {
            const err = new Error((data && data.error) || ('Fehler ' + response.status));
            err.data = data;
            err.status = response.status;
            throw err;
        }
        return data;
    };

    let toastTimer = null;
    PB.toast = function (message, isError) {
        const el = document.getElementById('toast');
        if (!el) return;
        el.textContent = message;
        el.classList.toggle('toast-error', !!isError);
        el.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => el.classList.remove('show'), isError ? 4500 : 2600);
    };

    /**
     * Renders the "now playing" block. Progress is interpolated client-side between polls.
     * Returns a function that updates the progress bar; call it every second.
     */
    PB.renderNowPlaying = function (container, state, options) {
        options = options || {};
        container.innerHTML = '';
        const np = state.nowPlaying;
        const sync = state.sync || {};

        if (!np || !np.track) {
            let text = 'Gerade läuft nichts.';
            if (sync.status === 'no_device') text = 'Kein aktives Spotify-Gerät. Der Host startet gleich die Musik.';
            else if (sync.status === 'ended') text = 'Die Party ist beendet.';
            else if (sync.status === 'error') text = sync.message || 'Spotify meldet einen Fehler.';
            else if (sync.status === 'idle') text = 'Warte auf den ersten Abgleich mit Spotify …';
            else if (sync.status === 'standby') text = sync.message || 'Eine andere Party steuert gerade die Musik.';
            container.appendChild(PB.h('div', { class: 'empty' }, text));
            return function () {};
        }

        const t = np.track;
        const fetchedAt = np.fetchedAt ? Date.parse(np.fetchedAt) : Date.now();
        const serverSkew = options.serverSkew || 0;
        const bar = PB.h('div', { class: 'progress-bar' });
        const elapsedEl = PB.h('span', null, PB.formatMs(np.progressMs));
        const totalEl = PB.h('span', null, PB.formatMs(t.durationMs));

        const statusBits = [];
        statusBits.push(np.isPlaying ? 'Spielt' : 'Pausiert');
        if (np.deviceName) statusBits.push('auf ' + np.deviceName);
        if (np.wishedBy) statusBits.push('gewünscht von ' + np.wishedBy);

        const wrap = PB.h('div', { class: options.big ? 'display-now' : 'now' },
            PB.cover(t.imageUrl, t.title, options.big ? 'cover-big' : ''),
            PB.h('div', { class: 'track-meta' },
                PB.h('div', { class: 'section-label' }, np.isPlaying ? 'Läuft gerade' : 'Pause'),
                PB.h('div', { class: 'now-title', title: t.title }, t.title),
                PB.h('div', { class: 'now-sub', title: t.artists }, t.artists + (t.album ? ' · ' + t.album : '')),
                PB.h('div', { class: 'now-status' }, statusBits.join(' · ')),
                PB.h('div', { class: 'progress' }, bar),
                PB.h('div', { class: 'time' }, elapsedEl, totalEl)
            )
        );
        container.appendChild(wrap);

        function tick() {
            let progress = np.progressMs;
            if (np.isPlaying) progress += (Date.now() - serverSkew - fetchedAt);
            progress = Math.min(progress, t.durationMs);
            bar.style.width = (t.durationMs ? (100 * progress / t.durationMs) : 0) + '%';
            elapsedEl.textContent = PB.formatMs(progress);
        }
        tick();
        return tick;
    };

    /** Updates the small status pill (host and display pages). */
    PB.renderSyncPill = function (state) {
        const pill = document.getElementById('sync-pill');
        const text = document.getElementById('sync-text');
        if (!pill || !text) return;
        const sync = state.sync || {};
        pill.className = 'status-pill ' + (sync.status || 'idle');
        const labels = { ok: 'Verbunden', no_device: 'Kein Spotify-Gerät', error: 'Spotify-Fehler', ended: 'Beendet', idle: 'Bereit', standby: 'Andere Party steuert' };
        text.textContent = sync.message && sync.status !== 'ok' ? sync.message : (labels[sync.status] || sync.status || '');
        if (sync.status === 'ok' && sync.message) pill.title = sync.message;
    };

    PB.renderGuestCount = function (state) {
        const el = document.getElementById('guest-count');
        if (!el) return;
        const n = state.guestCount || 0;
        el.textContent = n === 1 ? '1 Gast' : n + ' Gäste';
    };

    /**
     * Polls the state endpoint. Pauses while the tab is hidden, backs off on errors.
     */
    PB.poll = function (url, intervalMs, onState) {
        let timer = null;
        let failures = 0;
        async function run() {
            if (document.hidden) { schedule(intervalMs); return; }
            try {
                const state = await PB.api(url);
                failures = 0;
                onState(state);
                schedule(intervalMs);
            } catch (e) {
                failures++;
                schedule(Math.min(intervalMs * (failures + 1), 20000));
            }
        }
        function schedule(ms) { clearTimeout(timer); timer = setTimeout(run, ms); }
        document.addEventListener('visibilitychange', () => { if (!document.hidden) run(); });
        schedule(intervalMs);
        return { now: run, stop: () => clearTimeout(timer) };
    };

    PB.readInitial = function (root) {
        try { return JSON.parse(root.dataset.initial || 'null'); } catch (e) { return null; }
    };

    PB.copyButtons = function () {
        document.querySelectorAll('[data-copy]').forEach((btn) => {
            btn.addEventListener('click', async () => {
                try {
                    await navigator.clipboard.writeText(btn.dataset.copy);
                    PB.toast('Link kopiert.');
                } catch (e) {
                    PB.toast('Kopieren nicht möglich: ' + btn.dataset.copy, true);
                }
            });
        });
    };

    window.PB = PB;
})(window, document);
