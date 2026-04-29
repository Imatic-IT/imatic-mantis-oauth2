# ImaticOAuth2

Mantis plugin that adds OAuth2 / OIDC login alongside the standard username+password form. Tested with Keycloak; any provider that exposes an OIDC discovery document (`.well-known/openid-configuration`) works.

## Requirements

- MantisBT 2.x
- PHP 7.4+
- `allow_url_fopen = On` in `php.ini` (used to fetch the OIDC discovery document and exchange tokens)

## Installation

1. Copy the `ImaticOAuth2/` directory to `mantis/plugins/`.
2. Go to **Manage → Plugins** in Mantis and install *ImaticOAuth2*.
3. Add provider configuration to `mantis/config/config_inc.php` (see below).

## Configuration

Add to `config/config_inc.php` — never commit secrets to version control:

```php
$g_imatic_oauth2_providers = [
    'keycloak' => [
        'discovery_url' => 'https://keycloak.example.com/realms/myrealm/.well-known/openid-configuration',
        'client_id'     => 'mantis',
        'client_secret' => 'REPLACE_WITH_SECRET',
        'label'         => 'Sign in with Keycloak',
        'enabled'       => true,
        // 'public_url' => 'http://localhost:8080',  // only needed when discovery_url uses an internal Docker hostname
    ],
];
```

Multiple providers are supported — add more keys to the array.

### `public_url`

When running Mantis and Keycloak in Docker, PHP reaches Keycloak via an internal hostname (e.g. `keycloak:8080`) but the browser cannot. Set `public_url` to the browser-accessible base URL of the provider:

```php
'discovery_url' => 'http://keycloak:8080/realms/myrealm/.well-known/openid-configuration',
'public_url'    => 'http://localhost:8080',
```

The plugin substitutes the internal hostname with `public_url` only for browser redirects (authorization and end_session). Token exchange stays on the internal network.

### Keycloak client setup

In Keycloak admin:
- **Valid redirect URIs:** `https://your-mantis.example.com/plugin.php?page=ImaticOAuth2/callback`
- **Valid post-logout redirect URIs:** `https://your-mantis.example.com/*`
- **Standard flow** enabled, **PKCE** supported

## How it works

### Login flow

```
1. User clicks "Sign in with Keycloak" on the login page
2. Plugin generates PKCE code_verifier + state, stores them in PHP session
3. Browser is redirected to Keycloak's authorization_endpoint
4. Keycloak authenticates the user and redirects back with ?code=...&state=...
5. Plugin verifies state, exchanges code for tokens (PKCE)
6. id_token payload is decoded to get email + sub (no extra userinfo request)
7. Mantis user is resolved by sub, then by email (auto-link), then error
8. auth_login_user() creates a standard Mantis session
9. Browser is redirected to the Mantis home page
```

### Logout flow

```
1. User clicks logout
2. Mantis core detects AuthFlags::setLogoutRedirectPage() for this user
   and redirects to the plugin logout page (no JS needed)
3. Plugin reads the user's provider from DB, then calls auth_logout()
4. Browser is redirected to Keycloak's end_session_endpoint
5. Keycloak clears its SSO session and redirects back to Mantis login page
```

### User resolution

The plugin never creates Mantis users automatically (configurable). On first OAuth login it matches the incoming identity to an existing Mantis account:

1. **By stored identity** — `imatic_oauth2_identities.subject` (stable across email changes)
2. **By email** — if `auto_link_by_email = true` (default) and the email from the token matches a Mantis user email

Once linked, the identity record is reused on subsequent logins. Admins can unlink identities via **Manage → OAuth2 Identities**.

## Database

```sql
CREATE TABLE {imatic_oauth2_identities} (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id   INT UNSIGNED NOT NULL,
    provider  VARCHAR(50) NOT NULL,
    subject   VARCHAR(255) NOT NULL,   -- OAuth2 "sub" claim
    email     VARCHAR(255),
    linked_at DATETIME NOT NULL,
    last_used DATETIME,
    UNIQUE KEY uq_provider_subject (provider, subject),
    INDEX idx_user_id (user_id)
);
```

The table is created automatically on plugin install via the Mantis schema system.

## Security

- **PKCE** (S256) is required on every flow — protects against authorization code interception.
- **State** parameter is verified on every callback — CSRF protection.
- `client_secret` is read from `config_inc.php` and never stored in the plugin files.
- `sub` (not email) is used as the stable identity key — email changes in the provider do not break the link.
- Logging in via OAuth never creates a new Mantis user unless explicitly implemented.
- Logout redirects to the provider's `end_session_endpoint` so the SSO session is also cleared.

## File structure

```
ImaticOAuth2/
├── ImaticOAuth2.php        — plugin registration, hooks, JS injection
├── ImaticOAuth2Core.php    — OIDC discovery, token exchange, user resolution
├── pages/
│   ├── callback.php        — handles ?action=start and the provider callback
│   ├── logout.php          — Mantis logout + provider end_session redirect
│   └── admin.php           — admin UI: list and unlink identities
├── config/
│   └── config.php          — configuration reference (no runtime logic)
├── js/
│   └── index.js            — webpack source
├── files/
│   └── index.js            — built bundle served by plugin_file()
└── webpack.config.js
```

## Microsoft Entra ID (Azure AD)

```php
'microsoft' => [
    'discovery_url' => 'https://login.microsoftonline.com/{tenant-id}/v2.0/.well-known/openid-configuration',
    'client_id'     => 'YOUR_APP_ID',
    'client_secret' => 'REPLACE_WITH_SECRET',
    'label'         => 'Sign in with Microsoft',
    'enabled'       => true,
],
```

Register a redirect URI in Azure: `https://your-mantis.example.com/plugin.php?page=ImaticOAuth2/callback`

