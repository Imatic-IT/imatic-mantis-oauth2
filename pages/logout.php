<?php
/**
 * OAuth2 logout — called by Mantis core via AuthFlags::setLogoutRedirectPage().
 * The user is still authenticated when this page is called, so we can look up
 * their provider before clearing the session.
 */

require_once dirname( __DIR__ ) . '/ImaticOAuth2Core.php';

$t_user_id    = auth_get_current_user_id();
$t_identities = ImaticOAuth2Core::getUserIdentities( $t_user_id );
$t_provider   = !empty( $t_identities ) ? $t_identities[0]['provider'] : '';

auth_logout();

$t_return_url = rtrim( config_get( 'path' ), '/' ) . '/' . IMATIC_OAUTH2_LOGIN_PAGE;

if( $t_provider ) {
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
