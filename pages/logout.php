<?php
/**
 * OAuth2 logout — signs out of Mantis and redirects to the provider's end_session endpoint.
 * Called instead of logout_page.php when an OAuth2 session cookie is present.
 */

$t_provider = isset( $_COOKIE[IMATIC_OAUTH2_COOKIE_NAME] ) ? $_COOKIE[IMATIC_OAUTH2_COOKIE_NAME] : '';

auth_logout();

setcookie( IMATIC_OAUTH2_COOKIE_NAME, '', [
    'expires'  => time() - 3600,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
] );

$t_return_url = rtrim( config_get( 'path' ), '/' ) . '/' . IMATIC_OAUTH2_LOGIN_PAGE;

if( $t_provider ) {
    require_once dirname( __DIR__ ) . '/ImaticOAuth2Core.php';

    $t_providers = ImaticOAuth2Core::getEnabledProviders();

    if( isset( $t_providers[$t_provider] ) ) {
        $t_redirect = ImaticOAuth2Core::getEndSessionUrl( $t_provider ) . '?' . http_build_query( [
            'client_id'                => $t_providers[$t_provider]['client_id'],
            'post_logout_redirect_uri' => $t_return_url,
        ] );

        header( 'Location: ' . $t_redirect );
        exit;
    }
}

print_header_redirect( $t_return_url );
