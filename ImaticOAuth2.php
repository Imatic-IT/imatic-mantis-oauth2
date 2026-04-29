<?php
define( 'IMATIC_OAUTH2_LOGIN_PAGE',    'login_page.php' );
define( 'IMATIC_OAUTH2_ACTION_START',  'start' );
define( 'IMATIC_OAUTH2_ACTION_LINK',   'link' );
define( 'IMATIC_OAUTH2_ACTION_UNLINK', 'unlink' );

class ImaticOAuth2Plugin extends MantisPlugin {

    public function register(): void {
        $this->name        = 'ImaticOAuth2';
        $this->description = 'OAuth2 / OIDC login for Mantis (Keycloak, Microsoft, Google)';
        $this->version     = '1.0.0';
        $this->requires    = ['MantisCore' => '2.3.0'];
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
            'EVENT_LAYOUT_RESOURCES' => 'injectLoginButtons',
            'EVENT_MENU_MANAGE'      => 'addManageMenu',
            'EVENT_AUTH_USER_FLAGS'  => 'authUserFlags',
        ];
    }

    public function authUserFlags(string $p_event, array $p_args): ?AuthFlags {
        $t_user_id = (int) ($p_args['user_id'] ?? 0);

        if (!$t_user_id || user_is_anonymous($t_user_id)) {
            return null;
        }

        require_once __DIR__ . '/ImaticOAuth2Core.php';

        if (empty(ImaticOAuth2Core::getUserIdentities($t_user_id))) {
            return null;
        }

        $t_flags = new AuthFlags();
        $t_flags->setLogoutRedirectPage(plugin_page('logout', true, 'ImaticOAuth2'));
        return $t_flags;
    }

    public function injectLoginButtons(string $p_event): void {
        if (!$this->isLoginPage()) {
            return;
        }

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

    public function addManageMenu(): array {
        if (!access_has_global_level(config_get('manage_plugin_threshold'))) {
            return [];
        }
        return ['<a href="' . plugin_page('admin') . '">OAuth2 Identity</a>'];
    }

    private function isLoginPage(): bool {
        return basename($_SERVER['SCRIPT_NAME'] ?? '') === IMATIC_OAUTH2_LOGIN_PAGE;
    }
}
