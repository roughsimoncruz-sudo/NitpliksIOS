(function () {
    'use strict';

    const KEYS = {
        watchlist: 'clique_watchlist',
        reviews: 'clique_reviews',
        progress: 'clique_continue_watching'
    };
    let signedIn = false;
    let syncing = false;
    let savePending = false;
    let initialized = false;
    let csrfToken = '';

    function read(key) {
        try {
            const value = JSON.parse(localStorage.getItem(key) || '[]');
            return Array.isArray(value) ? value : [];
        } catch (error) {
            console.error(`Unable to read ${key}:`, error);
            return [];
        }
    }

    function write(key, value) {
        localStorage.setItem(key, JSON.stringify(Array.isArray(value) ? value : []));
    }

    function snapshot() {
        return {
            watchlist: read(KEYS.watchlist),
            reviews: read(KEYS.reviews),
            progress: read(KEYS.progress)
        };
    }

    function mergeItems(serverItems, localItems) {
        const merged = new Map();
        const deleted = new Map();
        [...serverItems, ...localItems].forEach(item => {
            if (!item || !item.id) return;
            const key = `${item.id}:${item.type || ''}`;
            const updatedAt = Number(item.updatedAt) || 0;
            if (item.deleted) {
                if (!deleted.has(key) || updatedAt >= deleted.get(key)) deleted.set(key, updatedAt);
                return;
            }
            const current = merged.get(key);
            if (!current || updatedAt >= (Number(current.updatedAt) || 0)) merged.set(key, item);
        });
        return [...merged.entries()]
            .filter(([key, item]) => !deleted.has(key) || (Number(item.updatedAt) || 0) > deleted.get(key))
            .map(([, item]) => item);
    }

    async function save() {
        if (!signedIn || (window.profileStorage && window.profileStorage.activeProfileId !== 'guest')) return;
        if (syncing) {
            savePending = true;
            return;
        }
        syncing = true;
        try {
            do {
                savePending = false;
                const response = await fetch('sync.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify(snapshot())
                });
                if (!response.ok) throw new Error(`Sync failed (${response.status})`);
            } while (savePending);
        } catch (error) {
            savePending = false;
            console.error('Unable to sync account library:', error);
        } finally {
            syncing = false;
            if (savePending) window.setTimeout(save, 0);
        }
    }

    async function initialize() {
        try {
            const authResponse = await fetch('auth.php?action=me', { credentials: 'same-origin', cache: 'no-store' });
            const auth = await authResponse.json();
            signedIn = Boolean(auth.authenticated);
            csrfToken = auth.csrf_token || '';
            if (!signedIn || (window.profileStorage && window.profileStorage.activeProfileId !== 'guest')) return;

            const response = await fetch('sync.php', { credentials: 'same-origin', cache: 'no-store' });
            if (!response.ok) throw new Error(`Library load failed (${response.status})`);
            const server = await response.json();
            if (!initialized) {
                write(KEYS.watchlist, mergeItems(server.watchlist, read(KEYS.watchlist)));
                write(KEYS.reviews, mergeItems(server.reviews, read(KEYS.reviews)));
                write(KEYS.progress, mergeItems(server.progress, read(KEYS.progress)));
                initialized = true;
            }
            await save();
            window.dispatchEvent(new CustomEvent('clique-library-synced'));
        } catch (error) {
            console.error('Unable to load account library:', error);
        }
    }

    const originalSetItem = Storage.prototype.setItem;
    Storage.prototype.setItem = function (key, value) {
        originalSetItem.call(this, key, value);
        if (Object.values(KEYS).includes(key)) {
            window.setTimeout(save, 0);
        }
    };

    window.addEventListener('clique-account-changed', event => {
        signedIn = Boolean(event.detail?.authenticated);
        initialized = false;
        if (signedIn) initialize();
    });
    window.addEventListener('clique-library-synced', () => {
        if (typeof window.renderList === 'function') window.renderList();
    });
    window.addEventListener('load', initialize);
})();
