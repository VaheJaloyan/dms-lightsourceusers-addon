document.addEventListener('DOMContentLoaded', function () {
    const iframe = document.createElement('iframe');
    iframe.src = cdaSettings.iframeUrl;
    iframe.style.display = 'none';
    document.body.appendChild(iframe);

    iframe.onload = function () {
        if (document.cookie.includes('wordpress_logged_in')) {
            fetch(cdaSettings.ajaxUrl + '/generate-token', {
                method: 'GET',
                headers: { 'X-WP-Nonce': cdaSettings.nonce }
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        iframe.contentWindow.postMessage({
                            action: 'storeToken',
                            token: data.token
                        }, 'https://' + cdaSettings.domain);
                    }
                });
        } else {
            iframe.contentWindow.postMessage({ action: 'getToken' }, 'https://' + cdaSettings.domain);
        }
    };

    window.addEventListener('message', function (event) {
        if (event.origin !== 'https://' + cdaSettings.domain) return;

        const { action, token, success } = event.data;
        if (action === 'tokenResponse' && token) {
            fetch(cdaSettings.ajaxUrl + '/verify-token', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ token })
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    }
                });
        } else if (action === 'tokenCleared') {
            fetch(wpApiSettings.root + 'wp/v2/users/me/logout', {
                method: 'POST',
                headers: { 'X-WP-Nonce': cdaSettings.nonce }
            });
        }
    });

    document.addEventListener('click', function (event) {
        if (event.target.closest('a[href*="wp-login.php?action=logout"]')) {
            const iframe = document.querySelector('iframe[src="' + cdaSettings.iframeUrl + '"]');
            iframe.contentWindow.postMessage({ action: 'clearToken' }, 'https://' + cdaSettings.domain);
        }
    });
});