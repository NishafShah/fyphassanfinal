document.addEventListener('DOMContentLoaded', function () {
    const usersBody = document.getElementById('adminUsersBody');
    const emailsBody = document.getElementById('adminEmailsBody');
    const totalUsers = document.getElementById('totalUsers');
    const totalSent = document.getElementById('totalSent');
    const totalFailed = document.getElementById('totalFailed');
    const totalAll = document.getElementById('totalAll');
    const totalUploaded = document.getElementById('totalUploaded');
    const totalCreated = document.getElementById('totalCreated');
    const totalFiles = document.getElementById('totalFiles');
    const refreshBtn = document.getElementById('refreshAdminBtn');
    const alertBox = document.getElementById('adminAlert');
    const uploadedFilesBody = document.getElementById('adminUploadedFilesBody');
    const createdFilesBody = document.getElementById('adminCreatedFilesBody');
    const userSearchInput = document.getElementById('userSearchInput');
    const emailSearchInput = document.getElementById('emailSearchInput');

    function getAdminApiUrl() {
        if (typeof window.resolveBackendUrl === 'function') {
            return window.resolveBackendUrl().replace('backend/api/command_handler.php', 'backend/api/admin_emails.php');
        }

        const { protocol, origin, pathname } = window.location;
        let projectRoot = '';

        if (pathname.includes('/frontend/')) {
            projectRoot = pathname.replace(/\/frontend\/.*$/, '');
        } else {
            projectRoot = pathname.replace(/\/[^/]*$/, '');
        }

        if (protocol === 'file:') {
            const normalizedPath = pathname.replace(/\\/g, '/');
            const htdocsIndex = normalizedPath.toLowerCase().indexOf('/htdocs/');
            if (htdocsIndex !== -1) {
                projectRoot = normalizedPath.substring(htdocsIndex + '/htdocs'.length);
                if (projectRoot.includes('/frontend/')) {
                    projectRoot = projectRoot.replace(/\/frontend\/.*$/, '');
                } else {
                    projectRoot = projectRoot.replace(/\/[^/]*$/, '');
                }
            }
            const backendOrigin = window.BACKEND_ORIGIN || localStorage.getItem('virtualai_backend_origin') || 'http://localhost';
            return `${backendOrigin}${projectRoot}/backend/api/admin_emails.php`;
        }

        return `${origin}${projectRoot}/backend/api/admin_emails.php`;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    }

    function showAlert(type, message) {
        const color = type === 'error' ? 'var(--color-error)' : 'var(--color-success)';
        alertBox.innerHTML = `<div style="border:1px solid var(--color-gray-200);padding:10px 12px;border-radius:10px;color:${color};background:#fff;">${escapeHtml(message)}</div>`;
    }

    function formatDate(value) {
        if (!value) return '-';
        const d = new Date(value);
        if (Number.isNaN(d.getTime())) return value;
        return d.toLocaleString();
    }

    function formatBytes(bytes) {
        const n = Number(bytes) || 0;
        if (!n) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB'];
        let i = 0;
        let value = n;
        while (value >= 1024 && i < units.length - 1) {
            value /= 1024;
            i += 1;
        }
        return `${value.toFixed(i === 0 ? 0 : 2)} ${units[i]}`;
    }

    async function loadAdminData() {
        usersBody.innerHTML = '<tr><td colspan="6" style="padding:12px;color:var(--color-gray-500);">Loading user summary...</td></tr>';
        emailsBody.innerHTML = '<tr><td colspan="6" style="padding:12px;color:var(--color-gray-500);">Loading email logs...</td></tr>';
        uploadedFilesBody.innerHTML = '<tr><td colspan="5" style="padding:12px;color:var(--color-gray-500);">Loading uploaded files...</td></tr>';
        createdFilesBody.innerHTML = '<tr><td colspan="5" style="padding:12px;color:var(--color-gray-500);">Loading created files...</td></tr>';

        try {
            const userSearch = userSearchInput ? userSearchInput.value.trim() : '';
            const emailSearch = emailSearchInput ? emailSearchInput.value.trim() : '';
            const params = new URLSearchParams();
            params.set('limit', '300');
            if (userSearch) params.set('user_search', userSearch);
            if (emailSearch) params.set('email_search', emailSearch);

            const res = await fetch(getAdminApiUrl() + '?' + params.toString(), { credentials: 'same-origin' });
            const data = await res.json();

            if (!data.success) {
                showAlert('error', data.message || 'Failed to load admin analytics.');
                return;
            }

            totalUsers.textContent = data.summary.users_total || 0;
            totalSent.textContent = data.summary.sent_total || 0;
            totalFailed.textContent = data.summary.failed_total || 0;
            totalAll.textContent = data.summary.all_total || 0;
            totalUploaded.textContent = data.summary.uploaded_total || 0;
            totalCreated.textContent = data.summary.created_total || 0;
            totalFiles.textContent = data.summary.files_total || 0;

            const users = Array.isArray(data.users) ? data.users : [];
            if (!users.length) {
                usersBody.innerHTML = '<tr><td colspan="6" style="padding:12px;color:var(--color-gray-500);">No users found.</td></tr>';
            } else {
                usersBody.innerHTML = users.map(function (u) {
                    return `<tr style="border-bottom:1px solid var(--color-gray-100);">
                        <td style="padding:10px;"><strong>${escapeHtml(u.name || 'Unknown')}</strong><br><span style="font-size:12px;color:var(--color-gray-500);">${escapeHtml(u.email || '')}</span></td>
                        <td style="padding:10px;">${u.is_admin == 1 ? 'Admin' : 'User'}</td>
                        <td style="padding:10px;">${u.sent_count || 0}</td>
                        <td style="padding:10px;">${u.failed_count || 0}</td>
                        <td style="padding:10px;">${u.total_count || 0}</td>
                        <td style="padding:10px;">${escapeHtml(formatDate(u.last_email_at))}</td>
                    </tr>`;
                }).join('');
            }

            const emails = Array.isArray(data.emails) ? data.emails : [];
            if (!emails.length) {
                emailsBody.innerHTML = '<tr><td colspan="6" style="padding:12px;color:var(--color-gray-500);">No emails logged yet.</td></tr>';
            } else {
                emailsBody.innerHTML = emails.map(function (e) {
                    const statusColor = e.status === 'sent' ? 'var(--color-success)' : 'var(--color-error)';
                    return `<tr style="border-bottom:1px solid var(--color-gray-100);">
                        <td style="padding:10px;">${escapeHtml(formatDate(e.created_at))}</td>
                        <td style="padding:10px;"><strong>${escapeHtml(e.user_name || 'Unknown')}</strong><br><span style="font-size:12px;color:var(--color-gray-500);">${escapeHtml(e.user_email || '')}</span></td>
                        <td style="padding:10px;">${escapeHtml(e.sender || '')}</td>
                        <td style="padding:10px;">${escapeHtml(e.recipient || '')}</td>
                        <td style="padding:10px;">${escapeHtml(e.subject || '')}</td>
                        <td style="padding:10px;color:${statusColor};font-weight:600;">${escapeHtml(e.status || '')}</td>
                    </tr>`;
                }).join('');
            }

            const uploadedFiles = Array.isArray(data.uploaded_files)
                ? data.uploaded_files
                : (Array.isArray(data.files) ? data.files.filter(function (f) { return (f.created_via || '') === 'uploaded'; }) : []);
            const createdFiles = Array.isArray(data.created_files)
                ? data.created_files
                : (Array.isArray(data.files) ? data.files.filter(function (f) { return (f.created_via || 'created') !== 'uploaded'; }) : []);

            if (!uploadedFiles.length) {
                uploadedFilesBody.innerHTML = '<tr><td colspan="5" style="padding:12px;color:var(--color-gray-500);">No uploaded files found.</td></tr>';
            } else {
                uploadedFilesBody.innerHTML = uploadedFiles.map(function (f) {
                    return `<tr style="border-bottom:1px solid var(--color-gray-100);">
                        <td style="padding:10px;">${escapeHtml(formatDate(f.created_at))}</td>
                        <td style="padding:10px;"><strong>${escapeHtml(f.user_name || 'Unknown')}</strong><br><span style="font-size:12px;color:var(--color-gray-500);">${escapeHtml(f.user_email || '')}</span></td>
                        <td style="padding:10px;">${escapeHtml(f.filename || '')}</td>
                        <td style="padding:10px;">${escapeHtml(formatBytes(f.size))}</td>
                        <td style="padding:10px;">${escapeHtml(f.folder || '-')}</td>
                    </tr>`;
                }).join('');
            }

            if (!createdFiles.length) {
                createdFilesBody.innerHTML = '<tr><td colspan="5" style="padding:12px;color:var(--color-gray-500);">No created files found.</td></tr>';
            } else {
                createdFilesBody.innerHTML = createdFiles.map(function (f) {
                    return `<tr style="border-bottom:1px solid var(--color-gray-100);">
                        <td style="padding:10px;">${escapeHtml(formatDate(f.created_at))}</td>
                        <td style="padding:10px;"><strong>${escapeHtml(f.user_name || 'Unknown')}</strong><br><span style="font-size:12px;color:var(--color-gray-500);">${escapeHtml(f.user_email || '')}</span></td>
                        <td style="padding:10px;">${escapeHtml(f.filename || '')}</td>
                        <td style="padding:10px;">${escapeHtml(formatBytes(f.size))}</td>
                        <td style="padding:10px;">${escapeHtml(f.folder || '-')}</td>
                    </tr>`;
                }).join('');
            }
        } catch (error) {
            showAlert('error', 'Network error while loading admin analytics.');
        }
    }

    if (refreshBtn) {
        refreshBtn.addEventListener('click', loadAdminData);
    }

    function wireSearchInput(input) {
        if (!input) return;
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                loadAdminData();
            }
        });
    }

    wireSearchInput(userSearchInput);
    wireSearchInput(emailSearchInput);

    loadAdminData();
});
