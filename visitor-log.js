(function () {
    'use strict';

    function showAnnouncement(message) {
        const banner = document.createElement('aside');
        banner.setAttribute('role', 'status');
        banner.style.cssText = 'display:flex;align-items:center;justify-content:center;gap:1rem;padding:.7rem 1rem;background:#153b32;color:#e8fff7;font:600 14px system-ui,sans-serif;text-align:center';
        const text = document.createElement('span');
        text.textContent = message;
        const close = document.createElement('button');
        close.type = 'button';
        close.textContent = 'Dismiss';
        close.setAttribute('aria-label', 'Dismiss announcement');
        close.style.cssText = 'border:1px solid rgba(255,255,255,.35);border-radius:.5rem;padding:.3rem .6rem;color:inherit;background:transparent;cursor:pointer';
        close.addEventListener('click', () => banner.remove());
        banner.append(text, close);
        document.body.prepend(banner);
    }

    function showMaintenance(message) {
        document.title = 'Maintenance - Nitpliks';
        document.body.replaceChildren();
        document.body.style.cssText = 'min-height:100vh;margin:0;display:grid;place-items:center;background:#090b0b;color:#effbf5;font:16px system-ui,sans-serif';
        const main = document.createElement('main');
        main.style.cssText = 'max-width:38rem;padding:2rem;text-align:center';
        const heading = document.createElement('h1');
        heading.textContent = 'We’ll be back soon';
        heading.style.cssText = 'margin:0 0 1rem;font-size:2rem';
        const description = document.createElement('p');
        description.textContent = message;
        description.style.cssText = 'color:#aab8b1;line-height:1.6';
        main.append(heading, description);
        document.body.append(main);
    }

    function showAccessBlocked(reason) {
        document.title = 'Access blocked - Nitpliks';
        document.body.replaceChildren();
        document.body.style.cssText = 'min-height:100vh;margin:0;display:grid;place-items:center;background:#070914;color:#f5f2e9;font:16px system-ui,sans-serif;text-align:center';
        const main = document.createElement('main');
        main.style.cssText = 'max-width:32rem;margin:1rem;padding:2rem;border:1px solid #ff735f;border-radius:1rem';
        const heading = document.createElement('h1');
        heading.textContent = 'Access blocked';
        heading.style.cssText = 'color:#ffb4a7';
        const message = document.createElement('p');
        message.textContent = 'This IP address has been blocked from accessing Nitpliks.';
        message.style.cssText = 'color:#aeb5cc;line-height:1.6';
        const reasonLabel = document.createElement('strong');
        reasonLabel.textContent = 'Reason';
        reasonLabel.style.cssText = 'display:block;margin-bottom:.35rem;color:#ffb4a7';
        const reasonText = document.createElement('p');
        reasonText.textContent = reason || 'No reason was provided.';
        reasonText.style.cssText = 'margin:0;color:#aeb5cc;line-height:1.6;overflow-wrap:anywhere';
        const reasonBox = document.createElement('div');
        reasonBox.style.cssText = 'padding:1rem;border-radius:.6rem;background:rgba(255,115,95,.1)';
        reasonBox.append(reasonLabel, reasonText);
        main.append(heading, message, reasonBox);
        document.body.append(main);
    }

    fetch('features.php?action=site-status', { credentials: 'same-origin', cache: 'no-store' })
        .then(function (response) {
            if (!response.ok) throw new Error(`Site settings request failed (${response.status}).`);
            return response.json();
        })
        .then(async function (settings) {
            if (settings.announcement_enabled && settings.announcement_message) {
                showAnnouncement(settings.announcement_message);
            }
            if (!settings.maintenance_mode) return;

            const response = await fetch('auth.php?action=me', { credentials: 'same-origin', cache: 'no-store' });
            if (!response.ok) throw new Error(`Unable to check administrator access (${response.status}).`);
            const auth = await response.json();
            if (!auth.user || !auth.user.is_admin) {
                showMaintenance(settings.maintenance_message || 'We are performing scheduled maintenance. Please check back soon.');
            }
        })
        .catch(function (error) {
            console.error('Unable to apply website settings:', error);
        });

    const payload = JSON.stringify({
        page: window.location.pathname + window.location.search
    });

    fetch('visitor.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: payload,
        keepalive: true
    }).then(async function (response) {
        if (response.status !== 403) return;
        try {
            const result = await response.json();
            showAccessBlocked(result.reason);
        } catch (error) {
            console.error('Unable to read IP ban reason:', error);
            showAccessBlocked('');
        }
    }).catch(function () {
        if (navigator.sendBeacon) navigator.sendBeacon('visitor.php', new Blob([payload], { type: 'application/json' }));
    });
})();
