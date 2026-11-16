(function () {
    'use strict';

    const PROFILE_SCOPED_KEYS = new Set([
        'clique_watchlist',
        'clique_continue_watching',
        'clique_reviews',
        'clique_collections',
        'clique_recommendation_preferences'
    ]);
    const originalGetItem = Storage.prototype.getItem;
    const originalSetItem = Storage.prototype.setItem;
    const originalRemoveItem = Storage.prototype.removeItem;

    function activeProfileId() {
        return originalGetItem.call(localStorage, 'clique_active_profile') || 'guest';
    }

    function scopedKey(key) {
        return PROFILE_SCOPED_KEYS.has(key) ? `${key}:${activeProfileId()}` : key;
    }

    function migrateLegacyData() {
        PROFILE_SCOPED_KEYS.forEach(key => {
            const legacy = originalGetItem.call(localStorage, key);
            const guestKey = `${key}:guest`;
            if (legacy !== null && originalGetItem.call(localStorage, guestKey) === null) {
                originalSetItem.call(localStorage, guestKey, legacy);
            }
        });
    }

    migrateLegacyData();

    Storage.prototype.getItem = function (key) {
        return originalGetItem.call(this, scopedKey(key));
    };

    Storage.prototype.setItem = function (key, value) {
        return originalSetItem.call(this, scopedKey(key), value);
    };

    Storage.prototype.removeItem = function (key) {
        return originalRemoveItem.call(this, scopedKey(key));
    };

    window.profileStorage = {
        get activeProfileId() {
            return activeProfileId();
        },
        keyFor(key) {
            return scopedKey(key);
        }
    };
})();
