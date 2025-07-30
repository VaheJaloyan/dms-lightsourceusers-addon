document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('#loginform');
    if (form) {
        const redirectInput = form.querySelector('input[name="redirect_to"]');
        const submitBtn = document.getElementById('wp-submit');
        const redirectUrl = redirectInput ? redirectInput.value : '/';

        if (!form || !submitBtn || !window.cdaSettings || !cdaSettings.authPopup) return;

        form.addEventListener('submit', async function (e) {
            e.preventDefault();

            // Enhanced popup security configuration
            const popupConfig = {
                width: 500,
                height: 600,
                left: window.screenX + (window.outerWidth - 500) / 2,
                top: window.screenY + (window.outerHeight - 600) / 2,
                menubar: 'no',
                toolbar: 'no',
                location: 'no',
                resizable: 'yes',
                scrollbars: 'yes',
                status: 'no',
                'strict-origin-when-cross-origin': 'yes'
            };

            const popupFeatures = Object.entries(popupConfig)
                .map(([key, value]) => `${key}=${value}`)
                .join(',');

            const popupWindow = window.open('', 'ssoPopup', popupFeatures);

            if (!popupWindow) {
                console.error('Popup blocked');
                return;
            }

            try {
                const formData = new FormData(form);
                const loginResponse = await fetch(`${cdaSettings.ajaxUrl}login`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'X-WP-Nonce': cdaSettings.nonce,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                });

                if (!loginResponse.ok) {
                    throw new Error('Network response failed');
                }

                const data = await loginResponse.json();

                if (data.success && data.token) {
                    // Validate and sanitize URLs
                    const popupUrl = new URL(cdaSettings.authPopup, window.location.origin);
                    const sanitizedHosts = cdaSettings.host_list
                        .filter(host => /^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/.test(host));

                    // Add required parameters
                    popupUrl.searchParams.set('token', data.token);
                    popupUrl.searchParams.set('redirect_url', redirectUrl);
                    popupUrl.searchParams.set('action', 'login');
                    popupUrl.searchParams.set('_wpnonce', cdaSettings.nonce);

                    // Add sanitized hosts
                    sanitizedHosts.forEach(host => {
                        popupUrl.searchParams.append('host[]', host);
                    });

                    // Validate target origin
                    if (new URL(popupUrl).origin === window.location.origin) {
                        popupWindow.location.href = popupUrl.toString();
                    } else {
                        throw new Error('Invalid target origin');
                    }
                } else {
                    throw new Error(data.message || 'Authentication failed');
                }
            } catch (error) {
                console.error('Login error:', error);
                popupWindow.document.write(`
                    <div style="font-family:sans-serif;color:#e53e3e;padding:20px;">
                        <p>${error.message || 'Authentication failed. Please try again.'}</p>
                        <button onclick="window.close()">Close</button>
                    </div>
                `);
                popupWindow.document.close();
            }
        });
    }

    // Enhanced logout handling
    const logoutLink = document.querySelector('.ab-item[role="menuitem"][href*="action=logout"]');
    if (logoutLink) {
        logoutLink.addEventListener('click', async (e) => {
            e.preventDefault();

            try {
                // Validate popup URL
                const popupUrl = new URL(cdaSettings.authPopup, window.location.origin);
                const sanitizedHosts = cdaSettings.host_list
                    .filter(host => /^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/.test(host));

                // Add secure parameters
                popupUrl.searchParams.set('action', 'logout');
                popupUrl.searchParams.set('redirect_url', cdaSettings.logout_redirect_url);
                popupUrl.searchParams.set('_wpnonce', cdaSettings.nonce);

                // Add sanitized hosts
                sanitizedHosts.forEach(host => {
                    popupUrl.searchParams.append('host[]', host);
                });

                const popupConfig = {
                    width: 500,
                    height: 600,
                    left: window.screenX + (window.outerWidth - 500) / 2,
                    top: window.screenY + (window.outerHeight - 600) / 2,
                    menubar: 'no',
                    toolbar: 'no',
                    location: 'no',
                    resizable: 'yes',
                    scrollbars: 'yes',
                    status: 'no',
                    'strict-origin-when-cross-origin': 'yes'
                };

                const popupFeatures = Object.entries(popupConfig)
                    .map(([key, value]) => `${key}=${value}`)
                    .join(',');

                console.log(popupUrl.toString());
                const popupWindow = window.open(popupUrl.toString(), 'ssoLogoutPopup', popupFeatures);

            } catch (error) {
                console.error('Logout error:', error);
                alert('Logout failed. Please try again.');
            }
        });
    }
});