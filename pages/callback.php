<?php
/**
 * OAuth2 callback handler.
 *
 * Start flow:  plugin.php?page=ImaticOAuth2/callback&provider=keycloak&action=start
 * Provider callback: plugin.php?page=ImaticOAuth2/callback&code=...&state=...
 *   (provider is not in the callback URL — it is read from the session set by startFlow)
 *
 * Mantis core is already loaded via plugin.php.
 *
 * Keycloak Valid redirect URI: http://localhost:8001/plugin.php?page=ImaticOAuth2/callback
 */

require_once dirname(__DIR__) . '/ImaticOAuth2Core.php';
require_once dirname(__DIR__) . '/config/config.php';

$action = gpc_get_string('action', '');

if ($action === IMATIC_OAUTH2_ACTION_START) {
    $provider = gpc_get_string('provider', '');
    if (!$provider) {
        error_page('Missing OAuth2 provider');
        exit;
    }
} else {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $provider = $_SESSION['oauth2_provider'] ?? '';
    if (!$provider) {
        error_page('Missing OAuth2 provider — session expired or invalid request');
        exit;
    }
}

if ($action === IMATIC_OAUTH2_ACTION_START) {
    ImaticOAuth2Core::startFlow($provider);
    exit;
}

$code  = gpc_get_string('code', '');
$state = gpc_get_string('state', '');
$error = gpc_get_string('error', '');

if ($error) {
    $errorDesc = gpc_get_string('error_description', $error);
    error_page('OAuth2 error: ' . htmlspecialchars($errorDesc));
    exit;
}

if (!$code || !$state) {
    error_page('Invalid OAuth2 callback — missing code or state');
    exit;
}

try {
    $userId = ImaticOAuth2Core::handleCallback($provider, $code, $state);

    if (!auth_login_user($userId)) {
        error_page('Login failed — account is disabled or does not exist.');
        exit;
    }

    setcookie(IMATIC_OAUTH2_COOKIE_NAME, $provider, [
        'expires'  => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    print_header_redirect(config_get('default_home_page'));

} catch (Throwable $e) {
    error_page('OAuth2 login failed: ' . htmlspecialchars($e->getMessage()));
}
