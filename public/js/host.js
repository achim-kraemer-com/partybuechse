/* Partybüchse – host dashboard: live ranking with moderation. */
(function (window, document, PB) {
    'use strict';

    const root = document.getElementById('host-app');
    if (!root) return;

    const stateUrl = root.dataset.stateUrl;
    const removeUrl = root.dataset.removeUrl;
    const csrf = root.dataset.csrf;

    const els = {
        now: document.getElementById('now-playing'),
        ranking: document.getElementById('ranking'),
        history: document.getElementById('history'),
    };

    let state = PB.readInitial(root) || {};
    let progressTick = function () {};

    function removeForm(item) {
        const form = PB.h('form', { method: 'post', action: removeUrl.replace('__ID__', item.id), style: 'margin:0' },
            PB.h('input', { type: 'hidden', name: '_token', value: csrf }),
            PB.h('button', { class: 'btn btn-danger btn-sm', type: 'submit', title: 'Wunsch entfernen' }, PB.icon('trash'))
        );
        form.addEventListener('submit', (e) => {
            if (!confirm('„' + item.title + '“ aus der Liste nehmen?')) e.preventDefault();
        });
        return form;
    }

    function row(item, played) {
        const classes = ['track'];
        if (item.status === 'queued') classes.push('is-queued');
        if (item.position && item.position <= 3) classes.push('top-' + item.position);
        if (!item.position) classes.push('no-rank');

        return PB.h('li', { class: classes.join(' ') },
            item.position ? PB.h('div', { class: 'rank' }, String(item.position)) : null,
            PB.cover(item.imageUrl, item.title),
            PB.h('div', { class: 'track-meta' },
                PB.h('div', { class: 'track-title', title: item.title }, item.title, ' ',
                    item.status === 'queued' ? PB.h('span', { class: 'badge badge-queued' }, 'in der Warteschlange') : null),
                PB.h('div', { class: 'track-sub' }, item.artists,
                    item.suggestedBy ? PB.h('span', { class: 'by' }, ' · von ' + item.suggestedBy) : null,
                    played && item.playedAt ? PB.h('span', { class: 'by' }, ' · ' + new Date(item.playedAt).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })) : null)),
            PB.h('div', { class: 'row', style: 'gap:8px; flex-wrap:nowrap;' },
                PB.h('div', { class: 'vote-static' }, String(item.votes), PB.h('small', null, 'Stimmen')),
                played ? null : removeForm(item))
        );
    }

    function render() {
        progressTick = PB.renderNowPlaying(els.now, state);
        PB.renderSyncPill(state);
        PB.renderGuestCount(state);

        els.ranking.innerHTML = '';
        if (!(state.ranking || []).length) {
            els.ranking.appendChild(PB.h('li', { class: 'empty' }, 'Noch keine Wünsche. Zeig deinen Gästen den QR-Code!'));
        } else {
            state.ranking.forEach((item) => els.ranking.appendChild(row(item, false)));
        }

        els.history.innerHTML = '';
        if (!(state.history || []).length) {
            els.history.appendChild(PB.h('li', { class: 'empty' }, 'Noch nichts gespielt.'));
        } else {
            state.history.forEach((item) => els.history.appendChild(row(item, true)));
        }
    }

    PB.copyButtons();
    render();
    setInterval(() => progressTick(), 1000);
    PB.poll(stateUrl, 4000, (fresh) => { state = fresh; render(); });
})(window, document, window.PB);
