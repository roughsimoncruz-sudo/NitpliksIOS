(function () {
    'use strict';

    const admin = window.nitpliksAdmin;
    if (!admin) {
        console.error('Admin management controls could not start.');
        return;
    }

    const byId = id => document.getElementById(id);
    const post = (action, data) => admin.request(`features.php?action=${action}`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': admin.csrfToken
        },
        body: JSON.stringify(data)
    });

    function showUserDetails(result) {
        const user = result.user;
        const modal = byId('userDetailsModal');
        const summary = byId('userDetailsSummary');
        const content = byId('userDetailsContent');
        summary.textContent = `${user.username} · ${user.email}`;
        content.replaceChildren();

        const rows = [
            ['Account', user.is_admin ? `Administrator (${user.admin_role})` : 'Member'],
            ['Status', user.suspended ? 'Suspended' : 'Active'],
            ['Joined', user.created_at],
            ['Saved collections', String(result.collections)]
        ];
        rows.forEach(([label, value]) => {
            const row = document.createElement('div');
            row.className = 'admin-ban-card';
            const heading = document.createElement('strong');
            heading.textContent = label;
            const detail = document.createElement('small');
            detail.textContent = value || '—';
            row.append(heading, detail);
            content.append(row);
        });

        const libraryHeading = document.createElement('h3');
        libraryHeading.className = 'mt-3 text-sm font-black';
        libraryHeading.textContent = 'Library activity';
        content.append(libraryHeading);
        if (!result.library.length) {
            const empty = document.createElement('p');
            empty.className = 'admin-muted';
            empty.textContent = 'No saved library activity.';
            content.append(empty);
        } else {
            result.library.forEach(item => {
                const row = document.createElement('div');
                row.className = 'admin-ban-card';
                const collection = document.createElement('strong');
                collection.textContent = item.collection;
                const detail = document.createElement('small');
                detail.textContent = `${item.item_count} items · Last updated ${item.last_updated}`;
                row.append(collection, detail);
                content.append(row);
            });
        }

        modal.classList.remove('hidden');
        byId('closeUserDetails').focus();
    }

    async function loadSiteSettings() {
        const status = byId('siteSettingsStatus');
        try {
            const settings = await admin.request('features.php?action=site-settings');
            byId('maintenanceMode').checked = settings.maintenance_mode;
            byId('maintenanceMessage').value = settings.maintenance_message || '';
            byId('announcementEnabled').checked = settings.announcement_enabled;
            byId('announcementMessage').value = settings.announcement_message || '';
            status.textContent = '';
        } catch (error) {
            status.textContent = error.message;
            admin.setStatus(error.message);
        }
    }

    byId('siteSettingsForm').addEventListener('submit', async event => {
        event.preventDefault();
        const status = byId('siteSettingsStatus');
        status.textContent = 'Saving…';
        try {
            await post('save-site-settings', {
                maintenance_mode: byId('maintenanceMode').checked,
                maintenance_message: byId('maintenanceMessage').value,
                announcement_enabled: byId('announcementEnabled').checked,
                announcement_message: byId('announcementMessage').value
            });
            status.textContent = 'Site settings saved.';
            admin.setStatus('Site settings saved.');
        } catch (error) {
            status.textContent = error.message;
            admin.setStatus(error.message);
        }
    });

    byId('adminUsers').addEventListener('click', async event => {
        const button = event.target.closest('button');
        if (!button) return;

        if (button.dataset.userDetails) {
            try {
                const result = await admin.request(`features.php?action=admin-user-details&user_id=${encodeURIComponent(button.dataset.userDetails)}`);
                showUserDetails(result);
            } catch (error) {
                admin.setStatus(error.message);
            }
            return;
        }

        if (!button.dataset.adminAccess) return;
        const isAdmin = button.dataset.isAdmin === 'true';
        const username = button.dataset.username;
        const verb = isAdmin ? 'Grant administrator access to' : 'Revoke administrator access from';
        if (!window.confirm(`${verb} ${username}?`)) return;

        const roleChoice = Array.from(button.parentElement.querySelectorAll('[data-admin-role-choice]'))
            .find(select => select.dataset.adminRoleChoice === button.dataset.adminAccess);
        try {
            await post('admin-user-access', {
                user_id: Number(button.dataset.adminAccess),
                is_admin: isAdmin,
                admin_role: roleChoice ? roleChoice.value : 'moderator'
            });
            admin.setStatus(`${isAdmin ? 'Granted' : 'Revoked'} administrator access ${isAdmin ? 'for' : 'from'} ${username}.`);
            await admin.reloadUsers();
        } catch (error) {
            admin.setStatus(error.message);
        }
    });

    byId('closeUserDetails').addEventListener('click', () => byId('userDetailsModal').classList.add('hidden'));
    byId('userDetailsModal').addEventListener('click', event => {
        if (event.target === byId('userDetailsModal')) byId('userDetailsModal').classList.add('hidden');
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') byId('userDetailsModal').classList.add('hidden');
    });

    function resetFeaturedForm() {
        byId('featuredForm').reset();
        byId('featuredRecordId').value = '';
        byId('featuredActive').checked = true;
        byId('featuredSubmit').textContent = 'Add featured';
        byId('featuredCancel').classList.add('hidden');
    }

    function startFeaturedEdit(item) {
        byId('featuredRecordId').value = item.id;
        byId('featuredTitleId').value = item.title_id;
        byId('featuredType').value = item.media_type;
        byId('featuredTitle').value = item.title;
        byId('featuredImageUrl').value = item.image_url || '';
        byId('featuredNote').value = item.note || '';
        byId('featuredSortOrder').value = item.sort_order;
        byId('featuredActive').checked = item.is_active === true || Number(item.is_active) === 1;
        byId('featuredSubmit').textContent = 'Save changes';
        byId('featuredCancel').classList.remove('hidden');
        byId('featuredTitleId').focus();
    }

    byId('featuredCancel').addEventListener('click', resetFeaturedForm);
    byId('featuredItems').addEventListener('click', async event => {
        const button = event.target.closest('button[data-feature-action]');
        if (!button) return;

        if (button.dataset.featureAction === 'edit') {
            try {
                startFeaturedEdit(JSON.parse(button.dataset.feature));
            } catch (error) {
                admin.setStatus('Unable to open this featured item for editing.');
                console.error('Invalid featured item data:', error);
            }
            return;
        }

        try {
            if (button.dataset.featureAction === 'toggle') {
                const item = JSON.parse(button.dataset.feature);
                await post('save-featured-content', {
                    id: Number(item.id),
                    title_id: Number(item.title_id),
                    media_type: item.media_type,
                    title: item.title,
                    image_url: item.image_url || '',
                    note: item.note || '',
                    sort_order: Number(item.sort_order) || 0,
                    is_active: !(item.is_active === true || Number(item.is_active) === 1)
                });
                admin.setStatus('Featured visibility updated.');
            } else if (button.dataset.featureAction === 'delete') {
                const itemId = button.dataset.featureId;
                if (!window.confirm('Remove this featured title?')) return;
                await post('delete-featured-content', { id: Number(itemId) });
                admin.setStatus('Featured title removed.');
            }
            await admin.reloadFeatured();
        } catch (error) {
            admin.setStatus(error.message);
        }
    });

    if (admin.role === 'owner') loadSiteSettings();
})();
