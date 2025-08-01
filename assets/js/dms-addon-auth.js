document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('#loginform');

    // Build a centered popup config based on the popup's width/height
    const popupWidth = Math.round(window.outerWidth / 2);
    const popupHeight = Math.round(window.outerWidth / 4);

    // Browser-compatible origin of the current window on the desktop
    const originX = (typeof window.screenX !== 'undefined' ? window.screenX : window.screenLeft) || 0;
    const originY = (typeof window.screenY !== 'undefined' ? window.screenY : window.screenTop) || 0;

    // Use inner size for better results with zoom; fall back as needed
    const frameW = window.innerWidth || document.documentElement.clientWidth || window.outerWidth || popupWidth;
    const frameH = window.innerHeight || document.documentElement.clientHeight || window.outerHeight || popupHeight;

    // Center relative to the current window
    const left = Math.max(0, Math.round(originX + (frameW - popupWidth) / 2));
    const top = Math.max(0, Math.round(originY + (frameH - popupHeight) / 2));

    const popupConfig = {
        width: popupWidth,
        height: popupHeight,
        left,
        top,
        menubar: 'no',
        toolbar: 'no',
        location: 'no',
        resizable: 'yes',
        scrollbars: 'yes',
        status: 'no'
    };


    if (form) {
        const redirectInput = form.querySelector('input[name="redirect_to"]');
        const submitBtn = document.getElementById('wp-submit');
        const redirectUrl = redirectInput ? redirectInput.value : '/';

        if (!form || !submitBtn || !window.cdaSettings || !cdaSettings.authPopup) return;

        form.addEventListener('submit', async function (e) {
            e.preventDefault();

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
                // Close popup
                popupWindow.close();

                // Fallback: submit the form normally
                form.submit();
            }
        });
    }

    // Enhanced logout handling
    document.querySelectorAll('[href*="action=logout"]').forEach(logoutLink => {
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
    });
});