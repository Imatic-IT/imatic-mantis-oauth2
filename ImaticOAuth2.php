<?php
define( 'IMATIC_OAUTH2_LOGIN_PAGE',    'login_page.php' );
define( 'IMATIC_OAUTH2_COOKIE_NAME',  'imatic_oauth2_session' );
define( 'IMATIC_OAUTH2_ACTION_START', 'start' );
define( 'IMATIC_OAUTH2_ACTION_LINK',   'link' );
define( 'IMATIC_OAUTH2_ACTION_UNLINK', 'unlink' );

class ImaticOAuth2Plugin extends MantisPlugin {

    public function register(): void {
        $this->name        = 'ImaticOAuth2';
        $this->description = 'OAuth2 / OIDC login for Mantis (Keycloak, Microsoft, Google)';
        $this->version     = '1.0.0';
        $this->requires    = ['MantisCore' => '2.0.0'];
        $this->author      = 'Imatic It s.r.o.';
        $this->contact     = 'info@imatic.cz';
        $this->url         = 'https://www.imatic.cz/';
    }

    public function schema(): array {
        return [
            0 => ['CreateTableSQL', [db_get_table('imatic_oauth2_identities'), "
                id          I NOTNULL AUTOINCREMENT PRIMARY,
                user_id     I NOTNULL,
                provider    C(50) NOTNULL,
                subject     C(255) NOTNULL,
                email       C(255),
                linked_at   T NOTNULL,
                last_used   T
            "]],
        ];
    }

    public function config(): array {
        return [
            'auto_link_by_email' => true,
            'allow_registration' => false,
        ];
    }

    public function hooks(): array {
        return [
            'EVENT_LAYOUT_RESOURCES' => 'injectScript',
            'EVENT_MENU_MANAGE'      => 'addManageMenu',
        ];
    }

    public function injectScript(string $p_event): void {
        if ($this->isLoginPage()) {
            $this->injectLoginButtons();
        } else {
            $this->injectLogoutIntercept();
        }
    }

    public function addManageMenu(): array {
        if (!access_has_global_level(config_get('manage_plugin_threshold'))) {
            return [];
        }
        return ['<a href="' . plugin_page('admin') . '">OAuth2 Identity</a>'];
    }

    private function injectLogoutIntercept(): void {
        if (empty($_COOKIE[IMATIC_OAUTH2_COOKIE_NAME])) {
            return;
        }
        echo '<script data-oauth2-logout-url="' . plugin_page('logout', false, 'ImaticOAuth2') . '" src="' . plugin_file('index.js') . '"></script>';
    }

    private function injectLoginButtons(): void {
        require_once __DIR__ . '/ImaticOAuth2Core.php';
        require_once __DIR__ . '/config/config.php';

        $providers = ImaticOAuth2Core::getEnabledProviders();
        if (empty($providers)) {
            return;
        }

        $buttons = [];
        foreach ($providers as $key => $provider) {
            $url = html_entity_decode(
                plugin_page('callback', false, 'ImaticOAuth2'),
                ENT_QUOTES,
                'UTF-8'
            ) . '&provider=' . urlencode($key) . '&action=' . IMATIC_OAUTH2_ACTION_START;

            $buttons[] = [
                'url'   => $url,
                'label' => $provider['label'],
            ];
        }

        $buttonsJson = htmlspecialchars(
            json_encode($buttons, JSON_UNESCAPED_UNICODE),
            ENT_QUOTES,
            'UTF-8'
        );
        $orText = htmlspecialchars(plugin_lang_get('or_divider'), ENT_QUOTES, 'UTF-8');

        echo '<script data-oauth2-buttons="' . $buttonsJson . '" data-oauth2-or-text="' . $orText . '" src="' . plugin_file('index.js') . '"></script>';
    }

    private function isLoginPage(): bool {
        return basename($_SERVER['SCRIPT_NAME'] ?? '') === IMATIC_OAUTH2_LOGIN_PAGE;
    }
}
