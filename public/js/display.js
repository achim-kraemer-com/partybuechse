/* Partybüchse – big screen: now playing, ranking, QR code. */
(function (window, document, PB) {
    'use strict';

    const root = document.getElementById('display-app');
    if (!root) return;

    const stateUrl = root.dataset.stateUrl;
    const els = {
        now: document.getElementById('now-playing'),
        ranking: document.getElementById('ranking'),
    };

    let state = PB.readInitial(root) || {};
    let progressTick = function () {};

    function row(item) {
        const classes = ['track'];
        if (item.status === 'queued') classes.push('is-queued');
        if (item.position <= 3) classes.push('top-' + item.position);
        return PB.h('li', { class: classes.join(' ') },
            PB.h('div', { class: 'rank' }, String(item.position)),
            PB.cover(item.imageUrl, item.title),
            PB.h('div', { class: 'track-meta' },
                PB.h('div', { class: 'track-title', title: item.title }, item.title, ' ',
                    item.status === 'queued' ? PB.h('span', { class: 'badge badge-queued' }, 'läuft gleich') : null),
                PB.h('div', { class: 'track-sub' }, item.artists,
                    item.suggestedBy ? PB.h('span', { class: 'by' }, ' · von ' + item.suggestedBy) : null)),
            PB.h('div', { class: 'vote-static' }, String(item.votes), PB.h('small', null, 'Stimmen'))
        );
    }

    function render() {
        progressTick = PB.renderNowPlaying(els.now, state, { big: true });
        PB.renderSyncPill(state);
        PB.renderGuestCount(state);

        els.ranking.innerHTML = '';
        const list = (state.ranking || []).slice(0, 10);
        if (!list.length) {
            els.ranking.appendChild(PB.h('li', { class: 'empty' }, 'Noch keine Wünsche. Scannt den QR-Code!'));
            return;
        }
        list.forEach((item) => els.ranking.appendChild(row(item)));
    }

    render();
    setInterval(() => progressTick(), 1000);
    PB.poll(stateUrl, 3000, (fresh) => { state = fresh; render(); });
})(window, document, window.PB);
