document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('#loginform');
    const submitBtn = document.getElementById('wp-submit');

    const redirectInput = form.querySelector('input[name="redirect_to"]');
    const redirectUrl = redirectInput ? redirectInput.value : '/';

    if (!form || !submitBtn || !window.cdaSettings || !cdaSettings.authPopup) return;

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        // 🟢 OPEN POPUP IMMEDIATELY — browser allows this
        const popupWidth = 500;
        const popupHeight = 600;
        const left = window.screenX + (window.outerWidth - popupWidth) / 2;
        const top = window.screenY + (window.outerHeight - popupHeight) / 2;
        const popupFeatures = `width=${popupWidth},height=${popupHeight},left=${left},top=${top},menubar=no,toolbar=no,location=no,resizable=yes,scrollbars=yes,status=no`;

        const popupWindow = window.open('', 'ssoPopup', popupFeatures);

        if (!popupWindow) {
            alert('Popup blocked. Please allow popups for this site.');
            return;
        }

        // Send login request
        fetch('/wp-json/dms-addon-sso/v1/login', {
            method: 'POST',
            body: new FormData(form)
        })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const popupUrl = new URL(cdaSettings.authPopup);
                    cdaSettings.host_list.forEach(function (host) {
                        popupUrl.searchParams.append('host[]', host);
                    });

                    popupUrl.searchParams.set('token', data.token);
                    popupUrl.searchParams.set('redirect_url', redirectUrl);

                    // Now redirect the opened popup
                    popupWindow.location.href = popupUrl.toString();

                } else {
                    popupWindow.document.write(`<p style="font-family:sans-serif;">Login failed: ${data.message || 'Unknown error'}</p>`);
                    popupWindow.document.close();
                }
            })
            .catch((err) => {
                console.error('Login request failed:', err);
                popupWindow.document.write(`<p style="font-family:sans-serif;">Login request failed. Please try again.</p>`);
                popupWindow.document.close();
            });
    });
});
