/* Partybüchse – guest app: search, wish, vote. */
(function (window, document, PB) {
    'use strict';

    const root = document.getElementById('guest-app');
    if (!root) return;

    const urls = {
        state: root.dataset.stateUrl,
        search: root.dataset.searchUrl,
        suggest: root.dataset.suggestUrl,
        vote: root.dataset.voteUrl,
        nickname: root.dataset.nicknameUrl,
    };

    const els = {
        now: document.getElementById('now-playing'),
        votes: document.getElementById('votes-left'),
        ranking: document.getElementById('ranking'),
        history: document.getElementById('history'),
        results: document.getElementById('results'),
        search: document.getElementById('search'),
        spinner: document.getElementById('search-spinner'),
        nickname: document.getElementById('nickname'),
        nicknameSave: document.getElementById('nickname-save'),
    };

    let state = PB.readInitial(root) || {};
    let progressTick = function () {};
    let busy = false;

    function votesLeft() {
        return state.me ? state.me.votesLeft : 0;
    }

    function render() {
        progressTick = PB.renderNowPlaying(els.now, state);
        renderVotes();
        renderRanking();
        renderHistory();
        PB.renderGuestCount(state);
        if (els.results.children.length) refreshResultFlags();
    }

    function renderVotes() {
        els.votes.innerHTML = '';
        const total = state.party ? state.party.votesPerGuest : 3;
        const left = votesLeft();
        for (let i = 0; i < total; i++) {
            els.votes.appendChild(PB.h('span', { class: 'pip' + (i < left ? ' full' : '') }));
        }
        els.votes.appendChild(PB.h('span', { class: 'label' },
            left === 0 ? 'keine Stimme frei' : (left === 1 ? '1 Stimme frei' : left + ' Stimmen frei')));
    }

    function trackRow(item, opts) {
        const classes = ['track'];
        if (item.status === 'playing') classes.push('is-playing');
        if (item.mine) classes.push('is-mine');
        if (item.position && item.position <= 3) classes.push('top-' + item.position);
        if (!item.position) classes.push('no-rank');

        const subBits = [item.artists];
        const sub = PB.h('div', { class: 'track-sub', title: item.artists }, subBits.join(' · '),
            item.suggestedBy ? PB.h('span', { class: 'by' }, ' · von ' + (item.mine ? 'dir' : item.suggestedBy)) : null);

        const title = PB.h('div', { class: 'track-title', title: item.title },
            item.title, ' ',
            item.status === 'playing' ? PB.h('span', { class: 'badge badge-playing' }, 'läuft gerade') : null);

        let action;
        if (opts.played) {
            action = PB.h('div', { class: 'vote-static' }, String(item.votes), PB.h('small', null, 'Stimmen'));
        } else {
            const canVote = state.party && state.party.active && item.status === 'open' && (item.voted || votesLeft() > 0);
            action = PB.h('button', {
                class: 'vote-btn' + (item.voted ? ' is-voted' : ''),
                type: 'button',
                disabled: !canVote && !item.voted,
                title: item.voted ? 'Stimme zurücknehmen' : 'Dafür stimmen',
                'aria-pressed': item.voted ? 'true' : 'false',
                onclick: () => toggleVote(item),
            }, PB.icon('up'), PB.h('span', { class: 'count' }, String(item.votes)));
            if (item.status !== 'open') action.disabled = true;
        }

        return PB.h('li', { class: classes.join(' '), dataset: { id: item.id } },
            item.position ? PB.h('div', { class: 'rank' }, String(item.position)) : null,
            PB.cover(item.imageUrl, item.title),
            PB.h('div', { class: 'track-meta' }, title, sub),
            action
        );
    }

    function renderRanking() {
        els.ranking.innerHTML = '';
        const list = state.ranking || [];
        if (!list.length) {
            els.ranking.appendChild(PB.h('li', { class: 'empty' }, 'Noch keine Wünsche. Mach den Anfang!'));
            return;
        }
        list.forEach((item) => els.ranking.appendChild(trackRow(item, {})));
    }

    function renderHistory() {
        els.history.innerHTML = '';
        const list = state.history || [];
        if (!list.length) {
            els.history.appendChild(PB.h('li', { class: 'empty' }, 'Noch nichts gespielt.'));
            return;
        }
        list.forEach((item) => els.history.appendChild(trackRow(item, { played: true })));
    }

    async function toggleVote(item) {
        if (busy) return;
        busy = true;
        try {
            const data = await PB.api(urls.vote.replace('__ID__', item.id), { method: 'POST' });
            state = data;
            render();
            PB.toast(data.voted ? 'Stimme abgegeben.' : 'Stimme zurückgenommen.');
        } catch (e) {
            if (e.data && e.data.ranking) { state = e.data; render(); }
            PB.toast(e.message, true);
        } finally {
            busy = false;
        }
    }

    /* ---------- search ---------- */
    let searchTimer = null;
    let lastQuery = '';
    let searchSeq = 0;

    els.search.addEventListener('input', () => {
        clearTimeout(searchTimer);
        const q = els.search.value.trim();
        if (q.length < 2) { els.results.innerHTML = ''; lastQuery = ''; return; }
        searchTimer = setTimeout(() => search(q), 350);
    });
    els.search.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); clearTimeout(searchTimer); search(els.search.value.trim()); }
        if (e.key === 'Escape') { els.search.value = ''; els.results.innerHTML = ''; }
    });

    async function search(q) {
        if (q.length < 2 || q === lastQuery) return;
        lastQuery = q;
        const seq = ++searchSeq;
        els.spinner.classList.remove('hidden');
        try {
            const data = await PB.api(urls.search + '?q=' + encodeURIComponent(q));
            if (seq !== searchSeq) return;
            renderResults(data.results || []);
        } catch (e) {
            if (seq !== searchSeq) return;
            els.results.innerHTML = '';
            els.results.appendChild(PB.h('li', { class: 'empty' }, e.message));
        } finally {
            if (seq === searchSeq) els.spinner.classList.add('hidden');
        }
    }

    function renderResults(results) {
        els.results.innerHTML = '';
        if (!results.length) {
            els.results.appendChild(PB.h('li', { class: 'empty' }, 'Nichts gefunden. Versuch es mit Titel und Künstler.'));
            return;
        }
        results.forEach((track) => {
            const btn = PB.h('button', {
                class: 'btn btn-primary wish-btn',
                type: 'button',
                dataset: { uri: track.uri },
                onclick: () => suggest(track, btn),
            }, track.pending ? 'Auf der Liste' : 'Wünschen');
            if (track.pending) { btn.className = 'btn btn-ghost wish-btn'; }
            els.results.appendChild(PB.h('li', { class: 'track no-rank' },
                PB.cover(track.imageUrl, track.title),
                PB.h('div', { class: 'track-meta' },
                    PB.h('div', { class: 'track-title', title: track.title }, track.title, ' ',
                        track.explicit ? PB.h('span', { class: 'badge badge-explicit' }, 'E') : null),
                    PB.h('div', { class: 'track-sub' }, track.artists + ' · ' + PB.formatMs(track.durationMs))),
                btn
            ));
        });
    }

    function refreshResultFlags() {
        const pending = new Set((state.ranking || []).map((s) => s.uri));
        els.results.querySelectorAll('button[data-uri]').forEach((btn) => {
            const isPending = pending.has(btn.dataset.uri);
            btn.textContent = isPending ? 'Auf der Liste' : 'Wünschen';
            btn.className = (isPending ? 'btn btn-ghost' : 'btn btn-primary') + ' wish-btn';
        });
    }

    async function suggest(track, btn) {
        if (busy) return;
        busy = true;
        btn.disabled = true;
        try {
            const data = await PB.api(urls.suggest, { method: 'POST', body: { uri: track.uri } });
            state = data;
            render();
            PB.toast(data.message || 'Wunsch gespeichert.');
            els.search.value = '';
            els.results.innerHTML = '';
            lastQuery = '';
            const row = els.ranking.querySelector('[data-id="' + data.suggestionId + '"]');
            if (row) row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } catch (e) {
            if (e.data && e.data.ranking) { state = e.data; render(); }
            PB.toast(e.message, true);
            btn.disabled = false;
        } finally {
            busy = false;
        }
    }

    /* ---------- nickname ---------- */
    async function saveNickname() {
        try {
            const data = await PB.api(urls.nickname, { method: 'POST', body: { nickname: els.nickname.value } });
            state = data;
            render();
            PB.toast(data.nickname ? 'Hallo ' + data.nickname + '!' : 'Name entfernt.');
            els.nickname.blur();
        } catch (e) {
            PB.toast(e.message, true);
        }
    }
    els.nicknameSave.addEventListener('click', saveNickname);
    els.nickname.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); saveNickname(); } });

    /* ---------- live updates ---------- */
    render();
    setInterval(() => progressTick(), 1000);
    PB.poll(urls.state, 4000, (fresh) => {
        if (busy) return;
        state = fresh;
        render();
    });
})(window, document, window.PB);
