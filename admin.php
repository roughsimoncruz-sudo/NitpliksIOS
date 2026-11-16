<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/config.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId < 1) {
    http_response_code(401);
    exit('Administrator sign-in required.');
}

$query = database()->prepare('SELECT is_admin, admin_role FROM users WHERE id = ?');
$query->execute([$userId]);
$admin = $query->fetch();
if (!$admin || !(int) $admin['is_admin']) {
    http_response_code(403);
    exit('Administrator access required.');
}
?>
<!doctype html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Dashboard - Nitpliks</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="theme.css">
    <link rel="stylesheet" href="admin.css?v=4">
</head>
<body>
    <main class="admin-shell mx-auto max-w-6xl px-4 py-5 sm:px-6 sm:py-8">
        <nav class="admin-topbar" aria-label="Admin navigation">
            <a href="index.html" class="admin-brand"><img src="nitpliks-logo.png?v=40" alt="Nitpliks"></a>
            <div class="flex items-center gap-2">
                <button id="refreshTop" class="admin-action" type="button" aria-label="Refresh dashboard">↻ <span>Refresh</span></button>
                <a href="index.html" class="admin-action">← <span>Discover</span></a>
            </div>
        </nav>
        <header class="admin-heading mt-8 sm:mt-10">
            <div>
                <p class="admin-eyebrow">Nitpliks / Admin workspace</p>
                <h1>Control center</h1>
                <p>Manage your site, members, content, and activity from one place.</p>
            </div>
            <div class="admin-heading-meta">
                <div class="admin-live"><span></span> Dashboard status</div>
                <strong><?= htmlspecialchars(ucfirst((string) $admin['admin_role']), ENT_QUOTES, 'UTF-8') ?> access</strong>
            </div>
        </header>
        <p id="status" class="admin-status" role="status" aria-live="polite"></p>
        <div class="admin-workspace">
            <aside class="admin-sidebar" aria-label="Dashboard navigation">
                <nav class="admin-section-nav" aria-label="Dashboard sections">
                    <div class="admin-nav-group">
                        <p>Workspace</p>
                        <a href="#overview" data-section-link aria-current="location"><span>01</span>Overview</a>
                        <a href="#siteControls" data-section-link><span>02</span>Site settings</a>
                    </div>
                    <div class="admin-nav-group">
                        <p>Manage</p>
                        <a href="#users" data-section-link><span>03</span>User accounts</a>
                        <a href="#content" data-section-link><span>04</span>Featured content</a>
                        <a href="#moderation" data-section-link><span>05</span>Moderation</a>
                        <a href="#ipBansPanel" data-section-link><span>06</span>IP bans</a>
                    </div>
                    <div class="admin-nav-group">
                        <p>Insights</p>
                        <a href="#analyticsPanel" data-section-link><span>07</span>Analytics</a>
                        <a href="#visitorsPanel" data-section-link><span>08</span>Visitors</a>
                        <a href="#securityPanel" data-section-link><span>09</span>Security</a>
                        <a href="#auditPanel" data-section-link><span>10</span>Admin activity</a>
                    </div>
                </nav>
            </aside>
            <div class="admin-content">
                <section id="overview" class="admin-overview">
                    <div class="admin-overview-heading">
                        <div>
                            <p class="admin-eyebrow">Workspace overview</p>
                            <h2>At a glance</h2>
                            <p>Key activity and shortcuts for your administration tasks.</p>
                        </div>
                        <button id="refresh" class="admin-action" type="button" aria-label="Refresh dashboard">↻ <span>Refresh data</span></button>
                    </div>
                    <div id="stats" class="admin-stat-grid mt-5"></div>
                    <section id="quickActions" class="admin-quick-actions" aria-label="Quick actions">
                        <a href="features.php?action=export-visitor-csv" class="admin-quick-card"><strong>Export visitors</strong><span>Download the latest visitor CSV →</span></a>
                        <button id="focusSecurity" class="admin-quick-card" type="button"><strong>Review security</strong><span>Jump to recent security events →</span></button>
                        <button id="focusVisitors" class="admin-quick-card" type="button"><strong>Inspect traffic</strong><span>View visitor activity and devices →</span></button>
                    </section>
                </section>
        <section id="siteControls" class="hidden glass-panel mt-7 rounded-2xl p-4">
            <div class="mb-4"><h2 class="text-lg font-black">Site controls</h2><p class="mt-1 text-xs text-slate-400">Set a public announcement or temporarily place the site in maintenance mode. Administrators retain access.</p></div>
            <form id="siteSettingsForm" class="grid gap-4 md:grid-cols-2">
                <label class="admin-setting-card">
                    <span><input id="maintenanceMode" type="checkbox"> Enable maintenance mode</span>
                    <small>Visitors see the maintenance message. Admins can still manage the site.</small>
                    <textarea id="maintenanceMessage" maxlength="500" rows="3" placeholder="Maintenance message"></textarea>
                </label>
                <label class="admin-setting-card">
                    <span><input id="announcementEnabled" type="checkbox"> Show site announcement</span>
                    <small>Display this message across public pages.</small>
                    <textarea id="announcementMessage" maxlength="500" rows="3" placeholder="Announcement message"></textarea>
                </label>
                <div class="flex flex-wrap items-center gap-3 md:col-span-2">
                    <button class="rounded-lg border border-cyan-400/30 px-3 py-2 text-xs font-bold text-cyan-200" type="submit">Save site settings</button>
                    <p id="siteSettingsStatus" class="admin-muted" role="status"></p>
                </div>
            </form>
        </section>
        <section id="users" class="glass-panel mt-7 rounded-2xl p-4">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div><h2 class="text-lg font-black">User accounts</h2><p class="mt-1 text-xs text-slate-400">Review account details, manage access, and control administrator privileges.</p></div>
                <input id="userSearch" class="rounded-lg border border-white/10 bg-slate-950 px-3 py-2 text-xs" placeholder="Search username or email">
            </div>
            <div id="adminUsers" class="mt-4 grid gap-2"></div>
        </section>
        <div id="userDetailsModal" class="hidden fixed inset-0 z-50 overflow-y-auto bg-black/80 p-4" role="dialog" aria-modal="true" aria-labelledby="userDetailsHeading">
            <section class="glass-panel mx-auto mt-10 max-w-xl rounded-2xl p-5">
                <div class="flex items-start justify-between gap-4">
                    <div><h2 id="userDetailsHeading" class="text-lg font-black">User details</h2><p id="userDetailsSummary" class="mt-1 text-xs text-slate-400"></p></div>
                    <button id="closeUserDetails" class="admin-action" type="button" aria-label="Close user details">Close</button>
                </div>
                <div id="userDetailsContent" class="mt-4 grid gap-2"></div>
            </section>
        </div>
        <section id="content" class="glass-panel mt-7 rounded-2xl p-4">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div><h2 class="text-lg font-black">Featured content</h2><p class="mt-1 text-xs text-slate-400">Control titles shown in featured placements.</p></div>
                <form id="featuredForm" class="flex flex-wrap gap-2">
                    <input id="featuredRecordId" type="hidden">
                    <input id="featuredTitleId" type="number" min="1" class="w-24 rounded-lg border border-white/10 bg-slate-950 px-3 py-2 text-xs" placeholder="TMDB ID" required>
                    <select id="featuredType" class="rounded-lg border border-white/10 bg-slate-950 px-3 py-2 text-xs"><option value="movie">Movie</option><option value="tv">Series</option></select>
                    <input id="featuredTitle" class="rounded-lg border border-white/10 bg-slate-950 px-3 py-2 text-xs" placeholder="Title" required>
                    <input id="featuredImageUrl" class="rounded-lg border border-white/10 bg-slate-950 px-3 py-2 text-xs" placeholder="Image URL (optional)">
                    <input id="featuredNote" maxlength="255" class="rounded-lg border border-white/10 bg-slate-950 px-3 py-2 text-xs" placeholder="Note (optional)">
                    <input id="featuredSortOrder" type="number" class="w-24 rounded-lg border border-white/10 bg-slate-950 px-3 py-2 text-xs" placeholder="Order">
                    <label class="flex items-center gap-2 text-xs text-slate-300"><input id="featuredActive" type="checkbox" checked> Visible</label>
                    <button id="featuredSubmit" class="rounded-lg border border-cyan-400/30 px-3 py-2 text-xs font-bold text-cyan-200" type="submit">Add featured</button>
                    <button id="featuredCancel" class="hidden rounded-lg border border-white/10 px-3 py-2 text-xs font-bold" type="button">Cancel edit</button>
                </form>
            </div>
            <div id="featuredItems" class="mt-4 grid gap-2"></div>
        </section>
        <section id="moderation" class="glass-panel mt-7 rounded-2xl p-4">
            <div class="flex items-end justify-between gap-3"><div><h2 class="text-lg font-black">Reports and moderation</h2><p class="mt-1 text-xs text-slate-400">Review reported titles, reviews, and comments.</p></div></div>
            <div id="adminReports" class="mt-4 grid gap-2"></div>
            <h3 class="mt-6 text-sm font-black">Submitted reviews</h3>
            <div id="adminReviews" class="mt-3 grid gap-2"></div>
        </section>
        <section id="auditPanel" class="glass-panel mt-7 rounded-2xl p-4">
            <h2 class="text-lg font-black">Administrator activity</h2>
            <div id="adminAudit" class="mt-4 overflow-x-auto"></div>
        </section>
        <section id="ipBansPanel" class="glass-panel mt-7 rounded-2xl p-4">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div><h2 class="text-lg font-black">Blocked IP addresses</h2><p class="mt-1 text-xs text-slate-400">Banned addresses are rejected by PHP endpoints and the static-page visitor gate.</p></div>
                <form id="banForm" class="flex flex-wrap gap-2">
                    <input id="banIp" class="rounded-lg border border-white/10 bg-slate-950 px-3 py-2 text-xs" placeholder="IP address" required>
                    <input id="banReason" class="rounded-lg border border-white/10 bg-slate-950 px-3 py-2 text-xs" placeholder="Reason (optional)">
                    <button class="rounded-lg border border-rose-400/40 px-3 py-2 text-xs font-bold text-rose-200" type="submit">Ban IP</button>
                </form>
            </div>
            <div id="bannedIps" class="mt-4 grid gap-2 sm:grid-cols-2"></div>
        </section>
        <section id="analyticsPanel" class="glass-panel mt-7 rounded-2xl p-4">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <h2 class="text-lg font-black">Visitor analytics</h2>
                <div class="flex flex-wrap gap-2">
                    <select id="days" class="rounded-lg border border-white/10 bg-slate-950 px-3 py-2 text-xs"><option value="7">7 days</option><option value="30" selected>30 days</option><option value="90">90 days</option></select>
                    <input id="visitorSearch" class="rounded-lg border border-white/10 bg-slate-950 px-3 py-2 text-xs" placeholder="Search IP, page, browser">
                    <a href="features.php?action=export-visitor-csv" class="rounded-lg border border-cyan-400/30 px-3 py-2 text-xs font-bold text-cyan-200">Export CSV</a>
                    <button id="cleanup" class="rounded-lg border border-rose-400/30 px-3 py-2 text-xs font-bold text-rose-200">Delete logs older than 180 days</button>
                </div>
            </div>
            <div id="analytics" class="mt-5 grid gap-4 md:grid-cols-3"></div>
        </section>
        <section id="securityPanel" class="glass-panel mt-7 overflow-x-auto rounded-2xl p-4">
            <h2 class="mb-4 text-lg font-black">Security activity</h2>
            <table class="w-full min-w-[560px] text-left text-xs">
                <thead class="text-slate-400"><tr><th class="p-2">Time</th><th class="p-2">Event</th><th class="p-2">Email</th><th class="p-2">IP address</th></tr></thead>
                <tbody id="securityEvents"></tbody>
            </table>
        </section>
        <section id="visitorsPanel" class="glass-panel mt-7 overflow-x-auto rounded-2xl p-4">
            <div class="mb-4"><h2 class="text-lg font-black">Recent visitors</h2><p class="mt-1 text-xs text-slate-400">IP-based location is approximate, not an exact address. City and region appear only when Cloudflare's “Add visitor location headers” transform is enabled. Older visits may show unknown.</p></div>
            <table class="w-full min-w-[900px] text-left text-xs">
                <thead class="text-slate-400"><tr><th class="p-2">Time</th><th class="p-2">IP address</th><th class="p-2">Device</th><th class="p-2">Location</th><th class="p-2">Page</th><th class="p-2">Browser</th></tr></thead>
                <tbody id="visitors"></tbody>
            </table>
            <div id="visitorPager" class="admin-pager" aria-label="Visitor log pages"></div>
        </section>
            </div>
        </div>
    </main>
    <script>
        let csrf = '';
        let visitorPage = 1;
        const adminRole = <?= json_encode((string) $admin['admin_role'], JSON_UNESCAPED_SLASHES) ?>;
        const currentAdminId = <?= $userId ?>;
        const request = async (url, options) => { const response = await fetch(url, {credentials:'same-origin', ...options}); const result = await response.json(); if (!response.ok) throw new Error(result.error || 'Unable to load dashboard.'); return result; };
        const setStatus = message => { document.getElementById('status').textContent = message || ''; };
        const emptyState = (message, colSpan = 5) => { const row=document.createElement('tr'); const cell=document.createElement('td'); cell.colSpan=colSpan; cell.className='admin-empty'; cell.textContent=message; row.append(cell); return row; };
        const barList = (title, items, labelKey) => { const panel=document.createElement('div'); panel.className='admin-chart-card'; const heading=document.createElement('h3'); heading.textContent=title; panel.append(heading); if (!items.length) { const empty=document.createElement('p'); empty.className='admin-muted'; empty.textContent='No activity in this period.'; panel.append(empty); return panel; } const max=Math.max(...items.map(item=>Number(item.visits)),1); if (labelKey === 'day') { const chart=document.createElement('div'); chart.className='admin-column-chart'; items.slice(-14).forEach(item=>{const column=document.createElement('div');column.className='admin-column';const bar=document.createElement('span');bar.style.height=`${Math.max(8,Number(item.visits)/max*100)}%`;bar.title=`${item.day}: ${item.visits} visits`;const label=document.createElement('small');label.textContent=String(item.day).slice(5);column.append(bar,label);chart.append(column);}); panel.append(chart); return panel; } items.forEach(item=>{const row=document.createElement('div');row.className='admin-bar-row';const text=document.createElement('div');text.className='admin-bar-label';const label=document.createElement('span');label.textContent=item[labelKey] || '(direct)';const count=document.createElement('span');count.textContent=item.visits;text.append(label,count);const bar=document.createElement('div');bar.className='admin-bar';bar.style.width=`${Math.max(4,Number(item.visits)/max*100)}%`;row.append(text,bar);panel.append(row);});return panel; };
        const renderStats = stats => { const cards=[['Users',stats.users,'Registered accounts',''],['Collections',stats.collections,'Saved collections',''],['Visits today',stats.visitsToday,'Live audience pulse','accent'],['30-day visitors',stats.uniqueIps30Days,'Unique IP addresses',''],['30-day visits',stats.visits30Days,'Total page visits',''],['Security events',stats.securityEvents,'Last 30 days','warn']]; document.getElementById('stats').replaceChildren(...cards.map(([label,value,detail,variant])=>{const card=document.createElement('article');card.className=`admin-stat-card ${variant}`;card.innerHTML=`<span>${label}</span><strong>${Number(value).toLocaleString()}</strong><small>${detail}</small>`;return card;})); };
        const renderVisitorPager = visitors => { const pager=document.getElementById('visitorPager'); pager.replaceChildren(); if (!visitors.total) return; const summary=document.createElement('span'); summary.textContent=`Page ${visitors.page} of ${visitors.pages} · ${Number(visitors.total).toLocaleString()} visits`; const previous=document.createElement('button'); previous.type='button'; previous.textContent='← Previous'; previous.disabled=visitors.page <= 1; previous.addEventListener('click',()=>{visitorPage--;loadVisitors();}); const next=document.createElement('button'); next.type='button'; next.textContent='Next →'; next.disabled=visitors.page >= visitors.pages; next.addEventListener('click',()=>{visitorPage++;loadVisitors();}); pager.append(summary,previous,next); };
        const getVisitorOperatingSystem = userAgent => {
            if (/\bAndroid\b/i.test(userAgent)) return 'Android';
            if (/\b(iPhone|iPad|iPod)\b/i.test(userAgent)) return 'iOS';
            if (/\bCrOS\b/i.test(userAgent)) return 'ChromeOS';
            if (/\bWindows NT\b/i.test(userAgent)) return 'Windows';
            if (/\b(Macintosh|Mac OS X)\b/i.test(userAgent)) return 'macOS';
            if (/\bLinux\b/i.test(userAgent)) return 'Linux';
            return '';
        };
        const getVisitorBrowser = userAgent => {
            const browsers = [
                [/\b(?:Edg|EdgA|EdgiOS)\/([\d.]+)/i, 'Edge'],
                [/\b(?:OPR|OPiOS)\/([\d.]+)/i, 'Opera'],
                [/\bSamsungBrowser\/([\d.]+)/i, 'Samsung Internet'],
                [/\b(?:FxiOS|Firefox)\/([\d.]+)/i, 'Firefox'],
                [/\b(?:CriOS|Chrome)\/([\d.]+)/i, 'Chrome'],
                [/Version\/([\d.]+).*Safari/i, 'Safari'],
                [/\b(?:MSIE |Trident\/.*rv:)([\d.]+)/i, 'Internet Explorer']
            ];
            for (const [pattern, name] of browsers) {
                const match = userAgent.match(pattern);
                if (match) return `${name} ${match[1].split('.')[0]}`;
            }
            return 'Unknown';
        };
        const getVisitorLocation = item => {
            const code = String(item.country_code || '').trim().toUpperCase();
            let country = '';
            if (/^[A-Z]{2}$/.test(code) && code !== 'XX') {
                try {
                    country = new Intl.DisplayNames(['en'], { type: 'region' }).of(code) || code;
                } catch (error) {
                    country = code;
                }
            }
            const city = String(item.city || '').trim();
            const region = String(item.region || '').trim();
            const locality = [city, region].filter(Boolean).join(', ');
            return [locality, country].filter(Boolean).join(', ') || 'Unknown';
        };
        const loadVisitors = async () => {
            const query = new URLSearchParams({
                days: document.getElementById('days').value,
                search: document.getElementById('visitorSearch').value,
                page: String(visitorPage),
                per_page: '25'
            });
            const [analytics, visitors] = await Promise.all([
                request(`features.php?action=visitor-analytics&days=${query.get('days')}`),
                request(`features.php?action=visitor-logs&${query}`)
            ]);
            visitorPage = visitors.page;
            document.getElementById('analytics').replaceChildren(
                barList('Top pages', analytics.pages, 'page_url'),
                barList('Devices', analytics.devices, 'device_type'),
                barList('Daily visits', analytics.daily, 'day')
            );
            const rows = document.getElementById('visitors');
            rows.replaceChildren();
            if (!visitors.items.length) {
                rows.append(emptyState('No visitors match this filter.', 6));
                renderVisitorPager(visitors);
                return;
            }
            visitors.items.forEach(item => {
                const row = document.createElement('tr');
                row.className = 'border-t border-white/10';
                const userAgent = String(item.user_agent || '');
                const operatingSystem = getVisitorOperatingSystem(userAgent);
                const browser = getVisitorBrowser(userAgent);
                const device = item.device_type === 'Bot' || !operatingSystem
                    ? item.device_type
                    : `${item.device_type} · ${operatingSystem}`;
                const values = [
                    item.visited_at,
                    item.ip_address,
                    device,
                    getVisitorLocation(item),
                    item.page_url,
                    browser
                ];
                values.forEach(value => {
                    const cell = document.createElement('td');
                    cell.className = 'max-w-xs truncate p-2 text-slate-300';
                    cell.textContent = value || '-';
                    if (value === browser) cell.title = userAgent;
                    row.append(cell);
                });
                rows.append(row);
            });
            renderVisitorPager(visitors);
        };
        const loadSecurity = async () => { const result=await request('features.php?action=security-events'); const rows=document.getElementById('securityEvents'); rows.replaceChildren(); if (!result.items.length) { rows.append(emptyState('No security events recorded.', 4)); return; } result.items.forEach(item=>{const row=document.createElement('tr');row.className='border-t border-white/10';[item.created_at,item.event_type,item.email,item.ip_address].forEach(value=>{const cell=document.createElement('td');cell.className='p-2 text-slate-300';cell.textContent=value||'-';row.append(cell);});rows.append(row);}); };
        const loadBans = async () => { const result=await request('features.php?action=ip-bans'); const container=document.getElementById('bannedIps'); container.replaceChildren(); if (!result.items.length) { const empty=document.createElement('p'); empty.className='admin-muted'; empty.textContent='No IP addresses are currently banned.'; container.append(empty); return; } result.items.forEach(item=>{const card=document.createElement('div'); card.className='admin-ban-card'; const info=document.createElement('div'); const ip=document.createElement('strong'); ip.textContent=item.ip_address; const detail=document.createElement('small'); detail.textContent=`${item.reason || 'No reason provided'} · ${item.created_at}`; info.append(ip,detail); card.append(info); if (adminRole === 'owner') { const button=document.createElement('button'); button.type='button'; button.className='admin-unban'; button.textContent='Unban'; button.addEventListener('click',async()=>{if(!confirm(`Unban ${item.ip_address}?`))return; try { await request('features.php?action=unban-ip',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({ip_address:item.ip_address})}); await loadBans(); setStatus(`Unbanned ${item.ip_address}.`); } catch(error) { setStatus(error.message); }}); card.append(button); } container.append(card);}); };
        const loadAdminUsers = async () => {
            const result = await request(`features.php?action=admin-users&search=${encodeURIComponent(document.getElementById('userSearch').value)}`);
            const root = document.getElementById('adminUsers');
            root.replaceChildren();
            if (!result.items.length) {
                root.textContent = 'No users found.';
                return;
            }
            result.items.forEach(item => {
                const card = document.createElement('article');
                card.className = 'admin-ban-card';
                const info = document.createElement('div');
                const name = document.createElement('strong');
                name.textContent = `${item.username} · ${item.email}`;
                const detail = document.createElement('small');
                detail.textContent = `${item.is_admin ? `Administrator · ${item.admin_role}` : 'Member'} · ${item.suspended ? 'Suspended' : 'Active'} · ${item.created_at}`;
                info.append(name, detail);

                const actions = document.createElement('div');
                actions.className = 'admin-user-actions';
                const details = document.createElement('button');
                details.type = 'button';
                details.className = 'admin-unban';
                details.textContent = 'Details';
                details.dataset.userDetails = String(item.id);
                actions.append(details);

                if (item.is_admin) {
                    if (adminRole === 'owner') {
                        const role = document.createElement('select');
                        role.className = 'admin-role-select';
                        ['owner', 'moderator', 'analyst'].forEach(value => {
                            const option = document.createElement('option');
                            option.value = value;
                            option.textContent = value[0].toUpperCase() + value.slice(1);
                            option.selected = value === item.admin_role;
                            role.append(option);
                        });
                        role.disabled = Number(item.id) === currentAdminId;
                        role.addEventListener('change', async () => {
                            try {
                                await request('features.php?action=admin-user-role', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                                    body: JSON.stringify({ user_id: item.id, admin_role: role.value })
                                });
                                setStatus(`Updated ${item.username}'s administrator role.`);
                                await loadAdminUsers();
                            } catch (error) {
                                setStatus(error.message);
                                await loadAdminUsers();
                            }
                        });
                        actions.append(role);
                        if (Number(item.id) !== currentAdminId) {
                            const revoke = document.createElement('button');
                            revoke.type = 'button';
                            revoke.className = 'admin-unban';
                            revoke.textContent = 'Revoke admin';
                            revoke.dataset.adminAccess = String(item.id);
                            revoke.dataset.isAdmin = 'false';
                            revoke.dataset.username = item.username;
                            actions.append(revoke);
                        }
                    }
                } else {
                    if (['owner', 'moderator'].includes(adminRole) && Number(item.id) !== currentAdminId) {
                        const toggle = document.createElement('button');
                        toggle.type = 'button';
                        toggle.className = 'admin-unban';
                        toggle.textContent = item.suspended ? 'Unsuspend' : 'Suspend';
                        toggle.addEventListener('click', async () => {
                            try {
                                await request('features.php?action=admin-user-status', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                                    body: JSON.stringify({ user_id: item.id, suspended: !item.suspended })
                                });
                                await loadAdminUsers();
                            } catch (error) {
                                setStatus(error.message);
                            }
                        });
                        actions.append(toggle);
                    }
                    if (adminRole === 'owner') {
                        const role = document.createElement('select');
                        role.className = 'admin-role-select';
                        role.dataset.adminRoleChoice = String(item.id);
                        ['moderator', 'analyst', 'owner'].forEach(value => {
                            const option = document.createElement('option');
                            option.value = value;
                            option.textContent = value[0].toUpperCase() + value.slice(1);
                            role.append(option);
                        });
                        const grant = document.createElement('button');
                        grant.type = 'button';
                        grant.className = 'admin-unban';
                        grant.textContent = 'Grant admin';
                        grant.dataset.adminAccess = String(item.id);
                        grant.dataset.isAdmin = 'true';
                        grant.dataset.username = item.username;
                        actions.append(role, grant);
                        const remove = document.createElement('button');
                        remove.type = 'button';
                        remove.className = 'admin-unban';
                        remove.textContent = 'Delete';
                        remove.addEventListener('click', async () => {
                            if (!confirm(`Delete ${item.username}? This cannot be undone.`)) return;
                            try {
                                await request('features.php?action=admin-delete-user', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                                    body: JSON.stringify({ user_id: item.id })
                                });
                                await loadAdminUsers();
                            } catch (error) {
                                setStatus(error.message);
                            }
                        });
                        actions.append(remove);
                    }
                }

                card.append(info, actions);
                root.append(card);
            });
        };
        const loadFeatured = async () => {
            const result = await request('features.php?action=featured-content');
            const root = document.getElementById('featuredItems');
            root.replaceChildren();
            if (!result.items.length) {
                root.textContent = 'No featured titles yet.';
                return;
            }
            result.items.forEach(item => {
                const card = document.createElement('article');
                card.className = 'admin-ban-card';
                const info = document.createElement('div');
                const name = document.createElement('strong');
                name.textContent = `${item.title} (${item.media_type})`;
                const detail = document.createElement('small');
                const isActive = item.is_active === true || Number(item.is_active) === 1;
                detail.textContent = `TMDB ${item.title_id} · ${isActive ? 'Visible' : 'Hidden'} · Order ${item.sort_order}`;
                info.append(name, detail);
                const actions = document.createElement('div');
                actions.className = 'admin-user-actions';
                if (['owner', 'moderator'].includes(adminRole)) {
                    const edit = document.createElement('button');
                    edit.type = 'button';
                    edit.className = 'admin-unban';
                    edit.textContent = 'Edit';
                    edit.dataset.featureAction = 'edit';
                    edit.dataset.feature = JSON.stringify(item);
                    const toggle = document.createElement('button');
                    toggle.type = 'button';
                    toggle.className = 'admin-unban';
                    toggle.textContent = isActive ? 'Hide' : 'Publish';
                    toggle.dataset.featureAction = 'toggle';
                    toggle.dataset.feature = JSON.stringify(item);
                    const remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'admin-unban';
                    remove.textContent = 'Remove';
                    remove.dataset.featureAction = 'delete';
                    remove.dataset.featureId = String(item.id);
                    actions.append(edit, toggle, remove);
                }
                card.append(info, actions);
                root.append(card);
            });
        };
        const loadReports = async () => { const result=await request('features.php?action=admin-reports'); const root=document.getElementById('adminReports'); root.replaceChildren(); if(!result.items.length){root.textContent='No reports have been submitted.';return;} result.items.forEach(item=>{const card=document.createElement('article');card.className='admin-ban-card';const info=document.createElement('div');const name=document.createElement('strong');name.textContent=`${item.target_type} · ${item.target_key}`;const detail=document.createElement('small');detail.textContent=`${item.reason} · ${item.status} · ${item.created_at}`;info.append(name,detail);const actions=document.createElement('div');actions.className='admin-user-actions';if(item.status==='open'&&['owner','moderator'].includes(adminRole)){['resolved','dismissed'].forEach(status=>{const button=document.createElement('button');button.type='button';button.className='admin-unban';button.textContent=status[0].toUpperCase()+status.slice(1);button.onclick=async()=>{try{await request('features.php?action=resolve-report',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({id:item.id,status})});await loadReports();}catch(error){setStatus(error.message);}};actions.append(button);});}card.append(info,actions);root.append(card);}); };
        const loadReviews = async () => { const result=await request('features.php?action=admin-reviews'); const root=document.getElementById('adminReviews'); root.replaceChildren(); if(!result.items.length){root.textContent='No reviews have been submitted.';return;} result.items.forEach(item=>{let payload={};try{payload=JSON.parse(item.payload||'{}');}catch(error){console.error('Unable to parse review payload:',error);}const card=document.createElement('article');card.className='admin-ban-card';const info=document.createElement('div');const name=document.createElement('strong');name.textContent=`${payload.title||item.item_key} · ${item.username}`;const detail=document.createElement('small');detail.textContent=`Rating: ${payload.rating||'—'} · Updated ${item.updated_at}${payload.note?' · '+payload.note:''}`;info.append(name,detail);card.append(info);if(['owner','moderator'].includes(adminRole)){const button=document.createElement('button');button.type='button';button.className='admin-unban';button.textContent='Remove review';button.onclick=async()=>{if(!confirm('Remove this review?'))return;try{await request('features.php?action=delete-review',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({user_id:item.user_id,item_key:item.item_key})});await loadReviews();}catch(error){setStatus(error.message);}};card.append(button);}root.append(card);}); };
        const loadAudit = async () => { const result=await request('features.php?action=admin-audit'); const root=document.getElementById('adminAudit'); const table=document.createElement('table');table.className='w-full min-w-[600px] text-left text-xs';table.innerHTML='<thead class="text-slate-400"><tr><th class="p-2">Time</th><th class="p-2">Admin</th><th class="p-2">Action</th><th class="p-2">Target</th><th class="p-2">Details</th></tr></thead>';const body=document.createElement('tbody');result.items.forEach(item=>{const row=document.createElement('tr');row.className='border-t border-white/10';[item.created_at,item.admin_username||'-',item.action,`${item.target_type}:${item.target_key}`,item.details||'-'].forEach(value=>{const cell=document.createElement('td');cell.className='p-2 text-slate-300';cell.textContent=value;row.append(cell);});body.append(row);});table.append(body);root.append(table);};
        window.nitpliksAdmin = {
            request,
            setStatus,
            get csrfToken() { return csrf; },
            role: adminRole,
            currentUserId: currentAdminId,
            reloadUsers: loadAdminUsers,
            reloadFeatured: loadFeatured
        };
        const hasAnalytics = ['owner', 'analyst'].includes(adminRole);
        const canManageSite = adminRole === 'owner';
        document.getElementById('siteControls').classList.toggle('hidden', !canManageSite);
        document.getElementById('stats').hidden = !hasAnalytics;
        document.getElementById('quickActions').hidden = !hasAnalytics;
        document.getElementById('analyticsPanel').hidden = !hasAnalytics;
        document.getElementById('visitorsPanel').hidden = !hasAnalytics;
        document.getElementById('banForm').hidden = !canManageSite;
        document.getElementById('cleanup').hidden = !canManageSite;
        document.querySelectorAll('[href="features.php?action=export-visitor-csv"]').forEach(link => { link.hidden = !hasAnalytics; });
        document.querySelector('[href="#analyticsPanel"]').hidden = !hasAnalytics;
        if (!canManageSite) {
            document.querySelector('[href="#siteControls"]').hidden = true;
        }
        if (!hasAnalytics) {
            document.querySelector('[href="#visitorsPanel"]').hidden = true;
        }
        if (adminRole === 'analyst') {
            document.getElementById('featuredForm').hidden = true;
        }
        const sectionLinks = Array.from(document.querySelectorAll('[data-section-link]'));
        const setActiveSection = sectionId => {
            sectionLinks.forEach(link => {
                const isActive = link.hash === `#${sectionId}`;
                if (isActive) {
                    link.setAttribute('aria-current', 'location');
                } else {
                    link.removeAttribute('aria-current');
                }
            });
        };
        sectionLinks.forEach(link => {
            link.addEventListener('click', () => setActiveSection(link.hash.slice(1)));
        });
        if ('IntersectionObserver' in window) {
            const sectionObserver = new IntersectionObserver(entries => {
                const visibleSections = entries.filter(entry => entry.isIntersecting);
                if (!visibleSections.length) return;
                visibleSections.sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);
                setActiveSection(visibleSections[0].target.id);
            }, { rootMargin: '-15% 0px -65% 0px', threshold: 0 });
            document.querySelectorAll('.admin-content > section[id]').forEach(section => {
                if (!section.hidden && !section.classList.contains('hidden')) {
                    sectionObserver.observe(section);
                }
            });
        }
        const loadDashboard = async () => {
            document.body.classList.add('admin-loading');
            setStatus('Refreshing dashboard…');
            try {
                const auth = await request('auth.php?action=me');
                csrf = auth.csrf_token || '';
                const tasks = [
                    loadSecurity(), loadBans(), loadAdminUsers(), loadFeatured(),
                    loadReports(), loadReviews(), loadAudit()
                ];
                if (hasAnalytics) {
                    tasks.push(request('features.php?action=admin-stats').then(renderStats), loadVisitors());
                }
                await Promise.all(tasks);
                setStatus(`Updated ${new Date().toLocaleTimeString([], {hour:'numeric',minute:'2-digit'})}`);
            } catch(error) {
                setStatus(error.message);
            } finally {
                document.body.classList.remove('admin-loading');
            }
        };
        document.getElementById('refresh').addEventListener('click',loadDashboard);
        document.getElementById('refreshTop').addEventListener('click',loadDashboard);
        document.getElementById('userSearch').addEventListener('input',loadAdminUsers);
        document.getElementById('featuredForm').addEventListener('submit', async event => {
            event.preventDefault();
            const body = {
                id: document.getElementById('featuredRecordId').value || undefined,
                title_id: document.getElementById('featuredTitleId').value,
                media_type: document.getElementById('featuredType').value,
                title: document.getElementById('featuredTitle').value,
                image_url: document.getElementById('featuredImageUrl').value,
                note: document.getElementById('featuredNote').value,
                sort_order: document.getElementById('featuredSortOrder').value || 0,
                is_active: document.getElementById('featuredActive').checked
            };
            try {
                await request('features.php?action=save-featured-content', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    body: JSON.stringify(body)
                });
                event.target.reset();
                document.getElementById('featuredActive').checked = true;
                document.getElementById('featuredSubmit').textContent = 'Add featured';
                document.getElementById('featuredCancel').classList.add('hidden');
                await loadFeatured();
                setStatus(body.id ? 'Featured content updated.' : 'Featured content added.');
            } catch(error) {
                setStatus(error.message);
            }
        });
        document.getElementById('focusSecurity').addEventListener('click',()=>document.getElementById('securityPanel').scrollIntoView({behavior:'smooth'}));
        document.getElementById('focusVisitors').addEventListener('click',()=>document.getElementById('visitorsPanel').scrollIntoView({behavior:'smooth'}));
        document.getElementById('banForm').addEventListener('submit',async event=>{event.preventDefault(); try { await request('features.php?action=ban-ip',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({ip_address:document.getElementById('banIp').value,reason:document.getElementById('banReason').value})}); event.target.reset(); await loadBans(); setStatus('IP address banned.'); } catch(error) { setStatus(error.message); }});
        loadDashboard();
        document.getElementById('days').addEventListener('change',()=>{visitorPage=1;loadVisitors().catch(error=>document.getElementById('status').textContent=error.message);});
        document.getElementById('visitorSearch').addEventListener('input',()=>{clearTimeout(window.visitorSearchTimer);visitorPage=1;window.visitorSearchTimer=setTimeout(()=>loadVisitors().catch(error=>document.getElementById('status').textContent=error.message),300);});
        document.getElementById('cleanup').addEventListener('click',async()=>{if(!confirm('Delete visitor logs older than 180 days?'))return;try{const result=await request('features.php?action=delete-old-visitors',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({days:180})});setStatus(`Deleted ${result.deleted} old visitor logs.`);await loadDashboard();}catch(error){setStatus(error.message);}});
    </script>
    <script src="admin-management.js?v=2"></script>
    <script src="visitor-log.js?v=2"></script>
</body>
</html>
