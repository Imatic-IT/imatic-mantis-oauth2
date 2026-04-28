<?php
/**
 * ImaticOAuth2Core — OIDC discovery, token exchange, and user resolution.
 *
 * Requires allow_url_fopen = On in php.ini (used by discoverEndpoints and exchangeCode).
 */
class ImaticOAuth2Core {

    private const SESSION_STATE    = 'oauth2_state';
    private const SESSION_VERIFIER = 'oauth2_code_verifier';
    private const SESSION_PROVIDER = 'oauth2_provider';

    public static function getEnabledProviders(): array {
        static $cache = null;
        if ($cache === null) {
            $configured = config_get_global('imatic_oauth2_providers', []);
            $cache = array_filter($configured, fn($p) => !empty($p['enabled']));
        }
        return $cache;
    }

    public static function getProviderConfig(string $providerKey): array {
        $providers = self::getEnabledProviders();
        if (!isset($providers[$providerKey])) {
            trigger_error('ImaticOAuth2: unknown or disabled provider: ' . $providerKey, E_USER_ERROR);
        }
        return $providers[$providerKey];
    }

    public static function discoverEndpoints(string $discoveryUrl): array {
        static $cache = [];
        if (!isset($cache[$discoveryUrl])) {
            $json = @file_get_contents($discoveryUrl);
            if ($json === false) {
                trigger_error('ImaticOAuth2: cannot fetch discovery URL: ' . $discoveryUrl, E_USER_ERROR);
            }
            $cache[$discoveryUrl] = json_decode($json, true) ?: [];
        }
        return $cache[$discoveryUrl];
    }

    public static function getEndSessionUrl(string $providerKey): string {
        $provider  = self::getProviderConfig($providerKey);
        $endpoints = self::discoverEndpoints($provider['discovery_url']);

        $url = $endpoints['end_session_endpoint']
            ?? (preg_replace('#/\.well-known/openid-configuration$#', '', $provider['discovery_url']) . '/protocol/openid-connect/logout');

        return self::applyPublicUrl($url, $provider);
    }

    public static function startFlow(string $providerKey): void {
        $provider   = self::getProviderConfig($providerKey);
        $endpoints  = self::discoverEndpoints($provider['discovery_url']);

        $codeVerifier  = self::generateCodeVerifier();
        $codeChallenge = self::generateCodeChallenge($codeVerifier);
        $state         = bin2hex(random_bytes(16));

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION[self::SESSION_STATE]    = $state;
        $_SESSION[self::SESSION_VERIFIER] = $codeVerifier;
        $_SESSION[self::SESSION_PROVIDER] = $providerKey;

        $callbackUrl  = self::getCallbackUrl($providerKey);
        $authEndpoint = self::applyPublicUrl($endpoints['authorization_endpoint'], $provider);

        $params = http_build_query([
            'response_type'         => 'code',
            'client_id'             => $provider['client_id'],
            'redirect_uri'          => $callbackUrl,
            'scope'                 => 'openid email profile',
            'state'                 => $state,
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        header('Location: ' . $authEndpoint . '?' . $params);
        exit;
    }

    public static function handleCallback(string $providerKey, string $code, string $state): int {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!hash_equals($_SESSION[self::SESSION_STATE] ?? '', $state)) {
            trigger_error('ImaticOAuth2: state mismatch — possible CSRF', E_USER_ERROR);
        }
        if (($_SESSION[self::SESSION_PROVIDER] ?? '') !== $providerKey) {
            trigger_error('ImaticOAuth2: provider mismatch', E_USER_ERROR);
        }

        $codeVerifier = $_SESSION[self::SESSION_VERIFIER];
        unset($_SESSION[self::SESSION_STATE], $_SESSION[self::SESSION_VERIFIER], $_SESSION[self::SESSION_PROVIDER]);

        $provider  = self::getProviderConfig($providerKey);
        $endpoints = self::discoverEndpoints($provider['discovery_url']);

        $tokens = self::exchangeCode(
            $endpoints['token_endpoint'],
            $provider['client_id'],
            $provider['client_secret'],
            $code,
            $codeVerifier,
            self::getCallbackUrl($providerKey)
        );

        $userinfo = !empty($tokens['id_token'])
            ? self::decodeJwtPayload($tokens['id_token'])
            : self::fetchUserinfo($endpoints['userinfo_endpoint'], $tokens['access_token']);

        return self::resolveUser($providerKey, $userinfo);
    }

    private static function exchangeCode(
        string $tokenEndpoint, string $clientId, string $clientSecret,
        string $code, string $codeVerifier, string $redirectUri
    ): array {
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n"
                           . 'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
                'content' => http_build_query([
                    'grant_type'    => 'authorization_code',
                    'code'          => $code,
                    'redirect_uri'  => $redirectUri,
                    'code_verifier' => $codeVerifier,
                ]),
            ],
        ]);

        $json = @file_get_contents($tokenEndpoint, false, $context);
        if ($json === false) {
            trigger_error('ImaticOAuth2: token exchange failed', E_USER_ERROR);
        }
        return json_decode($json, true);
    }

    private static function fetchUserinfo(string $userinfoEndpoint, string $accessToken): array {
        $context = stream_context_create([
            'http' => [
                'header' => 'Authorization: Bearer ' . $accessToken,
            ],
        ]);
        $json = @file_get_contents($userinfoEndpoint, false, $context);
        if ($json === false) {
            trigger_error('ImaticOAuth2: userinfo fetch failed', E_USER_ERROR);
        }
        return json_decode($json, true);
    }

    public static function resolveUser(string $providerKey, array $userinfo): int {
        $subject = $userinfo['sub'] ?? '';
        $email   = strtolower(trim($userinfo['email'] ?? ''));

        if (!$subject) {
            trigger_error('ImaticOAuth2: missing "sub" claim in token', E_USER_ERROR);
        }

        // 1. Match by stored identity (sub is stable even if email changes)
        $row = self::findIdentity($providerKey, $subject);
        if ($row) {
            self::updateLastUsed((int) $row['id']);
            return (int) $row['user_id'];
        }

        // 2. Auto-link to existing Mantis user by email (if enabled)
        if ($email && plugin_config_get('auto_link_by_email')) {
            $userId = self::findMantisUserByEmail($email);
            if ($userId) {
                self::linkIdentity($userId, $providerKey, $subject, $email);
                return $userId;
            }
        }

        // TODO: auto-register new Mantis user when allow_registration = true
        trigger_error('ImaticOAuth2: no Mantis user found for ' . $email, E_USER_ERROR);
    }

    public static function findIdentity(string $provider, string $subject): ?array {
        $result = db_query(
            'SELECT * FROM {imatic_oauth2_identities} WHERE provider = ' . db_param() . ' AND subject = ' . db_param(),
            [$provider, $subject]
        );
        $row = db_fetch_array($result);
        return $row ?: null;
    }

    public static function linkIdentity(int $userId, string $provider, string $subject, string $email): void {
        db_query(
            'INSERT INTO {imatic_oauth2_identities} (user_id, provider, subject, email, linked_at)
             VALUES (' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ')',
            [$userId, $provider, $subject, $email, date('Y-m-d H:i:s')]
        );
    }

    public static function unlinkIdentity(int $identityId, int $userId): void {
        db_query(
            'DELETE FROM {imatic_oauth2_identities} WHERE id = ' . db_param() . ' AND user_id = ' . db_param(),
            [$identityId, $userId]
        );
    }

    public static function getAllIdentities(): array {
        return self::fetchRows(db_query(
            'SELECT * FROM {imatic_oauth2_identities} ORDER BY linked_at DESC'
        ));
    }

    public static function getUserIdentities(int $userId): array {
        return self::fetchRows(db_query(
            'SELECT * FROM {imatic_oauth2_identities} WHERE user_id = ' . db_param() . ' ORDER BY linked_at DESC',
            [$userId]
        ));
    }

    private static function fetchRows($result): array {
        $rows = [];
        while ($row = db_fetch_array($result)) {
            $rows[] = $row;
        }
        return $rows;
    }

    private static function findMantisUserByEmail(string $email): ?int {
        $result = db_query(
            'SELECT id FROM {user} WHERE LOWER(email) = ' . db_param() . ' AND enabled = ' . db_param(),
            [$email, true]
        );
        $row = db_fetch_array($result);
        return $row ? (int) $row['id'] : null;
    }

    private static function updateLastUsed(int $identityId): void {
        db_query(
            'UPDATE {imatic_oauth2_identities} SET last_used = ' . db_param() . ' WHERE id = ' . db_param(),
            [date('Y-m-d H:i:s'), $identityId]
        );
    }

    private static function applyPublicUrl(string $url, array $providerConfig): string {
        if (empty($providerConfig['public_url'])) {
            return $url;
        }
        $parsed       = parse_url($providerConfig['discovery_url']);
        $internalBase = $parsed['scheme'] . '://' . $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
        return str_replace($internalBase, rtrim($providerConfig['public_url'], '/'), $url);
    }

    private static function generateCodeVerifier(): string {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private static function generateCodeChallenge(string $verifier): string {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private static function getCallbackUrl(string $providerKey): string {
        $base = rtrim(config_get('path'), '/');
        $page = html_entity_decode(plugin_page('callback', false, 'ImaticOAuth2'), ENT_QUOTES, 'UTF-8');
        if (!preg_match('#^https?://#', $page)) {
            $page = $base . '/' . ltrim($page, '/');
        }
        return $page;
    }

    private static function decodeJwtPayload(string $jwt): array {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            trigger_error('ImaticOAuth2: invalid id_token format', E_USER_ERROR);
        }
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (empty($payload)) {
            trigger_error('ImaticOAuth2: cannot decode id_token payload', E_USER_ERROR);
        }
        return $payload;
    }
}
