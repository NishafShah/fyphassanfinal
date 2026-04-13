// Authentication utilities (session-based)
(function () {
    function getAuthApiUrl() {
        if (typeof window.resolveBackendUrl === 'function') {
            return window.resolveBackendUrl().replace('backend/api/command_handler.php', 'backend/api/auth.php');
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
            return `${backendOrigin}${projectRoot}/backend/api/auth.php`;
        }

        return `${origin}${projectRoot}/backend/api/auth.php`;
    }

    async function authRequest(payload, method) {
        const options = {
            method: method || 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin'
        };

        if (options.method !== 'GET') {
            options.body = JSON.stringify(payload || {});
        }

        const res = await fetch(getAuthApiUrl(), options);
        const text = await res.text();
        try {
            return JSON.parse(text);
        } catch (e) {
            return { success: false, message: 'Invalid server response.' };
        }
    }

    async function ensureAuthenticated() {
        const result = await authRequest(null, 'GET');
        if (!result.success) {
            const current = window.location.pathname.split('/').pop() || 'dashboard.html';
            window.location.href = `login.html?next=${encodeURIComponent(current)}`;
            return null;
        }
        return result.user || null;
    }

    function applyAdminVisibility(user) {
        const adminLinks = document.querySelectorAll('[data-admin-link=\"true\"]');
        adminLinks.forEach(function (link) {
            link.style.display = user && user.is_admin ? '' : 'none';
        });
    }

    function wireLogout(user) {
        applyAdminVisibility(user);

        const userNameEl = document.getElementById('currentUserName');
        if (userNameEl && user && user.name) {
            userNameEl.textContent = user.name;
        }

        function attachLogoutHandler(el) {
            if (!el) return;
            el.addEventListener('click', async function (e) {
                e.preventDefault();
                await authRequest({ action: 'logout' }, 'POST');
                window.location.href = 'login.html';
            });
        }

        attachLogoutHandler(document.getElementById('logoutBtn'));
        attachLogoutHandler(document.getElementById('logoutBtnMobile'));
    }

    function wireLoginPage() {
        const loginForm = document.getElementById('loginForm');
        const registerForm = document.getElementById('registerForm');
        const tabLogin = document.getElementById('tabLogin');
        const tabRegister = document.getElementById('tabRegister');
        const authMessage = document.getElementById('authMessage');

        if (!loginForm || !registerForm || !tabLogin || !tabRegister) {
            return;
        }

        function setTab(tab) {
            const loginActive = tab === 'login';
            loginForm.style.display = loginActive ? 'block' : 'none';
            registerForm.style.display = loginActive ? 'none' : 'block';
            tabLogin.classList.toggle('active', loginActive);
            tabRegister.classList.toggle('active', !loginActive);
            authMessage.textContent = '';
        }

        tabLogin.addEventListener('click', function () { setTab('login'); });
        tabRegister.addEventListener('click', function () { setTab('register'); });

        const loginSubmit = document.getElementById('loginSubmit');
        const registerSubmit = document.getElementById('registerSubmit');

        loginForm.addEventListener('submit', async function (e) {
            e.preventDefault();
            loginSubmit.disabled = true;
            authMessage.textContent = 'Signing in...';

            const email = document.getElementById('loginEmail').value.trim();
            const password = document.getElementById('loginPassword').value;
            const result = await authRequest({ action: 'login', email, password }, 'POST');

            loginSubmit.disabled = false;

            if (!result.success) {
                authMessage.textContent = result.message || 'Login failed.';
                authMessage.style.color = 'var(--color-error)';
                return;
            }

            const params = new URLSearchParams(window.location.search);
            const next = params.get('next') || 'dashboard.html';
            window.location.href = next;
        });

        registerForm.addEventListener('submit', async function (e) {
            e.preventDefault();
            registerSubmit.disabled = true;
            authMessage.textContent = 'Creating account...';

            const name = document.getElementById('registerName').value.trim();
            const email = document.getElementById('registerEmail').value.trim();
            const password = document.getElementById('registerPassword').value;
            const confirmPassword = document.getElementById('registerConfirmPassword').value;

            if (password !== confirmPassword) {
                registerSubmit.disabled = false;
                authMessage.textContent = 'Passwords do not match.';
                authMessage.style.color = 'var(--color-error)';
                return;
            }

            const result = await authRequest({ action: 'register', name, email, password }, 'POST');
            registerSubmit.disabled = false;

            if (!result.success) {
                authMessage.textContent = result.message || 'Registration failed.';
                authMessage.style.color = 'var(--color-error)';
                return;
            }

            window.location.href = 'dashboard.html';
        });

        authRequest(null, 'GET').then(function (result) {
            if (result && result.success) {
                window.location.href = 'dashboard.html';
            }
        });
    }

    document.addEventListener('DOMContentLoaded', async function () {
        const body = document.body;
        if (!body) return;

        if (body.dataset.authPage === 'true') {
            wireLoginPage();
            return;
        }

        if (body.dataset.requireAuth === 'true') {
            const user = await ensureAuthenticated();
            if (!user) return;
            if (body.dataset.requireAdmin === 'true' && !user.is_admin) {
                window.location.href = 'dashboard.html';
                return;
            }
            wireLogout(user);
        } else {
            authRequest(null, 'GET').then(function (result) {
                if (result && result.success) {
                    applyAdminVisibility(result.user || null);
                }
            });
        }
    });
})();
