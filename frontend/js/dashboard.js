document.addEventListener('DOMContentLoaded', function () {
    const totalEl = document.getElementById('myEmailTotal');
    const sentEl = document.getElementById('myEmailSent');
    const failedEl = document.getElementById('myEmailFailed');
    const lastSentEl = document.getElementById('myEmailLastSent');
    const listEl = document.getElementById('myEmailHistoryList');
    const categorySummaryEl = document.getElementById('myEmailCategorySummary');
    const alertEl = document.getElementById('myEmailHistoryAlert');
    const refreshBtn = document.getElementById('refreshEmailHistoryBtn');

    if (!totalEl || !sentEl || !failedEl || !lastSentEl || !listEl) {
        return;
    }

    function getEmailHistoryUrl() {
        if (typeof window.resolveBackendUrl === 'function') {
            return window.resolveBackendUrl().replace('backend/api/command_handler.php', 'backend/api/email_history.php');
        }
        return 'backend/api/email_history.php';
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    }

    function formatDateTime(value) {
        if (!value) return '-';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return value;
        return date.toLocaleString();
    }

    function showAlert(message, isError) {
        if (!alertEl) return;
        alertEl.textContent = message || '';
        alertEl.style.color = isError ? 'var(--color-error)' : 'var(--color-gray-600)';
    }

    function formatCategoryLabel(value) {
        return String(value || 'general')
            .split(/[-_\s]+/)
            .filter(Boolean)
            .map(function (part) {
                return part.charAt(0).toUpperCase() + part.slice(1);
            })
            .join(' ');
    }

    function renderCategorySummary(categories) {
        if (!categorySummaryEl) return;

        if (categories === null) {
            categorySummaryEl.innerHTML = '<span class="email-category-pill empty">Loading categories...</span>';
            return;
        }

        if (!Array.isArray(categories) || !categories.length) {
            categorySummaryEl.innerHTML = '<span class="email-category-pill empty">No categories yet</span>';
            return;
        }

        categorySummaryEl.innerHTML = categories.map(function (item) {
            const label = item.label || formatCategoryLabel(item.name);
            const count = Number(item.count || 0);
            return '<span class="email-category-pill">' + escapeHtml(label) + ' (' + escapeHtml(count) + ')</span>';
        }).join('');
    }

    async function loadMyEmailHistory() {
        listEl.innerHTML = '<p class="email-history-empty">Loading your email history...</p>';
        renderCategorySummary(null);
        showAlert('', false);

        try {
            const response = await fetch(getEmailHistoryUrl() + '?limit=10', {
                credentials: 'same-origin'
            });
            const result = await response.json();

            if (!result.success) {
                throw new Error(result.message || 'Failed to load email history.');
            }

            const data = result.data || {};
            const summary = data.summary || {};
            const categories = Array.isArray(data.category_summary) ? data.category_summary : [];
            const emails = Array.isArray(data.emails) ? data.emails : [];

            totalEl.textContent = summary.total || 0;
            sentEl.textContent = summary.sent || 0;
            failedEl.textContent = summary.failed || 0;
            lastSentEl.textContent = formatDateTime(summary.last_email_at);
            renderCategorySummary(categories);

            if (!emails.length) {
                listEl.innerHTML = '<p class="email-history-empty">Aap ne abhi tak koi email send nahi ki.</p>';
                return;
            }

            listEl.innerHTML = emails.map(function (email) {
                const status = String(email.status || '').toLowerCase();
                const category = formatCategoryLabel(email.category);
                return '<div class="email-history-item">' +
                    '<div><strong>' + escapeHtml(email.subject || '(No subject)') + '</strong><div class="email-history-meta">To: ' + escapeHtml(email.recipient || '') + '</div></div>' +
                    '<div><strong>' + escapeHtml(email.sender || '') + '</strong><div class="email-history-meta">From</div></div>' +
                    '<div><strong>' + escapeHtml(formatDateTime(email.created_at)) + '</strong><div class="email-history-meta">Sent time</div></div>' +
                    '<div class="email-history-category">' + escapeHtml(category) + '</div>' +
                    '<div class="email-history-status ' + escapeHtml(status) + '">' + escapeHtml(status || 'unknown') + '</div>' +
                '</div>';
            }).join('');
        } catch (error) {
            totalEl.textContent = '0';
            sentEl.textContent = '0';
            failedEl.textContent = '0';
            lastSentEl.textContent = '-';
            renderCategorySummary([]);
            listEl.innerHTML = '<p class="email-history-empty">Email history load nahi ho saki.</p>';
            showAlert(error.message || 'Email history load nahi ho saki.', true);
        }
    }

    if (refreshBtn) {
        refreshBtn.addEventListener('click', loadMyEmailHistory);
    }

    loadMyEmailHistory();
});
