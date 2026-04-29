(function() {

    // Login buttons — injected below the login form on the login page
    var loginScript = document.querySelector('script[data-oauth2-buttons]');
    if (loginScript) {
        var buttons;
        try {
            buttons = JSON.parse(loginScript.getAttribute('data-oauth2-buttons'));
        } catch (e) {
            buttons = [];
        }

        if (Array.isArray(buttons) && buttons.length) {
            function injectButtons() {
                var form = document.getElementById('login-form');
                if (!form) return;

                var wrapper = document.createElement('div');
                wrapper.style.cssText = 'margin-top:12px;';

                var divider = document.createElement('div');
                divider.style.cssText = 'text-align:center;margin:8px 0;color:#999;font-size:12px;';
                divider.textContent = loginScript.getAttribute('data-oauth2-or-text') || 'or';
                wrapper.appendChild(divider);

                buttons.forEach(function(btn) {
                    var a = document.createElement('a');
                    a.href = btn.url;
                    a.className = 'btn btn-default btn-block';
                    a.style.cssText = 'margin-bottom:6px;';
                    a.textContent = btn.label;
                    wrapper.appendChild(a);
                });

                form.parentNode.insertBefore(wrapper, form.nextSibling);
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', injectButtons);
            } else {
                injectButtons();
            }
        }
    }


    // Logout intercept — rewrites logout links to the plugin logout page so the
    // provider's end_session endpoint is called alongside the Mantis logout.
    var logoutScript = document.querySelector('script[data-oauth2-logout-url]');
    if (logoutScript) {
        var logoutUrl = logoutScript.getAttribute('data-oauth2-logout-url');

        function interceptLogout() {
            document.querySelectorAll('a[href*="logout_page.php"]').forEach(function(link) {
                link.href = logoutUrl;
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', interceptLogout);
        } else {
            interceptLogout();
        }
    }

})();
