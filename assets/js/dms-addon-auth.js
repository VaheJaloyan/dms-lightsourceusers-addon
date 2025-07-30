document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('#loginform');
    if (form) {
        const redirectInput = form.querySelector('input[name="redirect_to"]');
        const submitBtn = document.getElementById('wp-submit');
        const redirectUrl = redirectInput ? redirectInput.value : '/';

        if (!form || !submitBtn || !window.cdaSettings || !cdaSettings.authPopup) return;

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            // Popup security configuration
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
                status: 'no'
            };

            const popupFeatures = Object.entries(popupConfig)
                .map(([key, value]) => `${key}=${value}`)
                .join(',');

            const popupWindow = window.open('', 'ssoPopup', popupFeatures);

            if (!popupWindow) {
                alert('Popup blocked. Please allow popups for this site.');
                return;
            }

            // Get CSRF token from WordPress
            const nonce = cdaSettings.nonce;

            // Secure fetch request
            fetch('/wp-json/dms-addon-sso/v1/login', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-WP-Nonce': nonce,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: new FormData(form)
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (data.success && data.token) {
                    // Validate and sanitize URLs
                    const popupUrl = new URL(cdaSettings.authPopup, window.location.origin);
                    const sanitizedHosts = cdaSettings.host_list
                        .filter(host => /^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/.test(host));

                    sanitizedHosts.forEach(host => {
                        popupUrl.searchParams.append('host[]', host);
                    });

                    // Add required parameters
                    popupUrl.searchParams.set('token', data.token);
                    popupUrl.searchParams.set('redirect_url', redirectUrl);
                    popupUrl.searchParams.set('action', 'login');

                    // Origin validation for popup
                    const targetOrigin = new URL(popupUrl).origin;
                    if (targetOrigin === window.location.origin) {
                        popupWindow.location.href = popupUrl.toString();
                    } else {
                        throw new Error('Invalid target origin');
                    }
                } else {
                    throw new Error(data.message || 'Authentication failed');
                }
            })
            .catch((error) => {
                console.error('Login error:', error);
                popupWindow.document.write(`
                    <p style="font-family:sans-serif;color:#e53e3e;padding:20px;">
                        ${error.message || 'Authentication failed. Please try again.'}
                    </p>
                `);
                popupWindow.document.close();
            });
        });
    }

    // Secure logout handling
    const logoutLink = document.querySelector('.ab-item[role="menuitem"][href*="action=logout"]');
    if (logoutLink) {
        logoutLink.addEventListener('click', (e) => {
            e.preventDefault();

            // Validate logout URL
            const popupUrl = new URL(cdaSettings.authPopup, window.location.origin);
            const sanitizedHosts = cdaSettings.host_list
                .filter(host => /^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/.test(host));

            sanitizedHosts.forEach(host => {
                popupUrl.searchParams.append('host[]', host);
            });

            // Add secure parameters
            popupUrl.searchParams.set('action', 'logout');
            popupUrl.searchParams.set('redirect_url', cdaSettings.logout_redirect_url);
            popupUrl.searchParams.set('_wpnonce', cdaSettings.nonce);

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
                status: 'no'
            };

            const popupFeatures = Object.entries(popupConfig)
                .map(([key, value]) => `${key}=${value}`)
                .join(',');

            const popupWindow = window.open(popupUrl.toString(), 'ssoLogoutPopup', popupFeatures);
            if (!popupWindow) {
                alert('Popup blocked. Please allow popups for this site.');
            }
        });
    }
});
