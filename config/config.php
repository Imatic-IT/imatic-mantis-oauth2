<?php
/**
 * ImaticOAuth2 — provider configuration reference.
 *
 * Add the following block to mantis/config/config_inc.php (never commit secrets):
 *
 * $g_imatic_oauth2_providers = [
 *     'keycloak' => [
 *         'discovery_url' => 'https://keycloak.example.com/realms/myrealm/.well-known/openid-configuration',
 *         'client_id'     => 'mantis',
 *         'client_secret' => 'REPLACE_WITH_SECRET',   // never commit to VCS
 *         'label'         => 'Sign in with Keycloak',
 *         'enabled'       => true,
 *         // 'public_url' => 'http://localhost:8080',  // set when discovery_url uses an internal Docker hostname
 *     ],
 *     // 'microsoft' => [
 *     //     'discovery_url' => 'https://login.microsoftonline.com/{tenant}/v2.0/.well-known/openid-configuration',
 *     //     'client_id'     => 'YOUR_APP_ID',
 *     //     'client_secret' => 'REPLACE_WITH_SECRET',
 *     //     'label'         => 'Sign in with Microsoft',
 *     //     'enabled'       => true,
 *     // ],
 * ];
 */
