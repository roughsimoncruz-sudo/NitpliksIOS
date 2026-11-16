(function () {
    const API_KEY = window.TMDB_API_KEY || '295a780c3e5aae731d6f2e804c6d13e5';
    const API_BASE = 'https://api.themoviedb.org/3';
    let activeTrigger = null;
    let requestToken = 0;
    let activeStartAt = '';

    function createModal() {
        if (document.getElementById('sharedDetailsModal')) return;
        const modal = document.createElement('div');
        modal.id = 'sharedDetailsModal';
        modal.className = 'shared-details-modal';
        modal.setAttribute('aria-hidden', 'true');
        modal.innerHTML = `
            <section class="shared-details-dialog" role="dialog" aria-modal="true" aria-labelledby="sharedDetailsTitle">
                <button type="button" class="shared-details-close" aria-label="Close title details">✕</button>
                <div class="shared-details-hero"><div class="shared-details-trailer"></div></div>
                <div class="shared-details-content">
                    <p class="shared-details-kicker">Title details</p>
                    <h2 id="sharedDetailsTitle">Loading title...</h2>
                    <div class="shared-details-facts"></div>
                    <p class="shared-details-overview">Loading information...</p>
                    <div class="shared-details-actions">
                        <button type="button" class="shared-details-watch">Watch now</button>
                        <div class="shared-details-cast"></div>
                    </div>
                </div>
            </section>`;
        document.body.append(modal);
        modal.querySelector('.shared-details-close').addEventListener('click', close);
        modal.addEventListener('click', event => {
            if (event.target === modal) close();
        });
    }

    function chip(text) {
        const item = document.createElement('span');
        item.className = 'shared-details-chip';
        item.textContent = text;
        return item;
    }

    async function open(id, type, anime, trigger) {
        createModal();
        activeTrigger = trigger;
        activeStartAt = trigger?.dataset.startAt || '';
        const modal = document.getElementById('sharedDetailsModal');
        const token = ++requestToken;
        const safeType = type === 'tv' ? 'tv' : 'movie';
        const title = modal.querySelector('#sharedDetailsTitle');
        const facts = modal.querySelector('.shared-details-facts');
        const overview = modal.querySelector('.shared-details-overview');
        const cast = modal.querySelector('.shared-details-cast');
        const trailer = modal.querySelector('.shared-details-trailer');
        const watch = modal.querySelector('.shared-details-watch');
        title.textContent = 'Loading title...';
        facts.replaceChildren();
        cast.replaceChildren();
        overview.textContent = 'Loading information...';
        trailer.replaceChildren();
        trailer.classList.remove('no-trailer');
        watch.disabled = true;
        modal.classList.add('is-open');
        document.body.classList.add('shared-details-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('overflow-hidden');
        try {
            const query = endpoint => fetch(`${API_BASE}/${safeType}/${encodeURIComponent(id)}${endpoint}?api_key=${API_KEY}`);
            const [detailsResponse, creditsResponse, videosResponse] = await Promise.all([
                query(''),
                query('/credits'),
                query('/videos')
            ]);
            if (!detailsResponse.ok) throw new Error(`TMDB returned ${detailsResponse.status}`);
            const details = await detailsResponse.json();
            const credits = creditsResponse.ok ? await creditsResponse.json() : null;
            const videos = videosResponse.ok ? await videosResponse.json() : null;
            if (token !== requestToken) return;
            const name = details.title || details.name || 'Untitled';
            const year = (details.release_date || details.first_air_date || '').slice(0, 4);
            title.textContent = name;
            overview.textContent = details.overview || 'No synopsis is available for this title yet.';
            facts.append(
                chip(year || 'Year unavailable'),
                chip(`★ ${Number(details.vote_average || 0).toFixed(1)}`),
                chip(safeType === 'tv' ? 'Series' : 'Movie')
            );
            (details.genres || []).slice(0, 4).forEach(genre => facts.append(chip(genre.name)));
            (credits?.cast || []).slice(0, 5).forEach(actor => cast.append(chip(actor.name)));
            const video = (videos?.results || []).find(item =>
                item.site === 'YouTube' && item.type === 'Trailer' && item.key
            ) || (videos?.results || []).find(item => item.site === 'YouTube' && item.key);
            if (video) {
                const iframe = document.createElement('iframe');
                iframe.title = `${name} trailer`;
                iframe.allow = 'autoplay; encrypted-media; picture-in-picture';
                iframe.allowFullscreen = true;
                iframe.src = `https://www.youtube-nocookie.com/embed/${encodeURIComponent(video.key)}?autoplay=1&mute=0&controls=1&rel=0&playsinline=1&vq=hd1080`;
                trailer.append(iframe);
            } else {
                trailer.classList.add('no-trailer');
                trailer.textContent = 'Trailer unavailable';
            }
            watch.disabled = false;
            watch.onclick = () => {
                const url = new URL('watch.html', window.location.href);
                url.searchParams.set('id', id);
                url.searchParams.set('type', safeType);
                url.searchParams.set('anime', anime ? '1' : '0');
                if (activeStartAt) url.searchParams.set('startAt', activeStartAt);
                window.location.href = url.href;
            };
        } catch (error) {
            console.error('Unable to load shared title details:', error);
            title.textContent = 'Details unavailable';
            overview.textContent = 'We could not load information for this title. Please try again later.';
        }
    }

    function close() {
        const modal = document.getElementById('sharedDetailsModal');
        if (!modal) return;
        requestToken++;
        activeStartAt = '';
        modal.querySelector('.shared-details-trailer').replaceChildren();
        modal.classList.remove('is-open');
        document.body.classList.remove('shared-details-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('overflow-hidden');
        if (activeTrigger instanceof HTMLElement) activeTrigger.focus();
    }

    function intercept(event) {
        const link = event.target.closest('a[href*="watch.html?id="]');
        if (!link || link.dataset.directPlayer === 'true') return;
        const url = new URL(link.href, window.location.href);
        const id = url.searchParams.get('id');
        if (!id) return;
        event.preventDefault();
        open(id, url.searchParams.get('type'), url.searchParams.get('anime') === '1', link);
    }

    document.addEventListener('DOMContentLoaded', () => {
        createModal();
        document.addEventListener('click', intercept);
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && document.getElementById('sharedDetailsModal')?.classList.contains('is-open')) close();
        });
    });
})();
