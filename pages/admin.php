<?php
auth_reauthenticate();
access_ensure_global_level( config_get( 'manage_plugin_threshold' ) );

require_once dirname( __DIR__ ) . '/ImaticOAuth2Core.php';

if( gpc_get_string( 'action', '' ) === IMATIC_OAUTH2_ACTION_UNLINK ) {
    form_security_validate( 'imatic_oauth2_admin_unlink' );
    $t_identity_id = gpc_get_int( 'identity_id' );
    $t_user_id     = gpc_get_int( 'user_id' );
    ImaticOAuth2Core::unlinkIdentity( $t_identity_id, $t_user_id );
    form_security_purge( 'imatic_oauth2_admin_unlink' );
    print_successful_redirect( plugin_page( 'admin', true ) );
}

$t_identities = ImaticOAuth2Core::getAllIdentities();

if( !empty( $t_identities ) ) {
    $t_unique_user_ids = array_unique( array_column( $t_identities, 'user_id' ) );
    user_cache_array_rows( $t_unique_user_ids );
}

layout_page_header( 'OAuth2 Identities' );
layout_page_begin( 'manage_overview_page.php' );
print_manage_menu();
?>

<div class="col-md-12 col-xs-12">
    <div class="space-10"></div>
    <div class="widget-box widget-color-blue2">
        <div class="widget-header widget-header-small">
            <h4 class="widget-title lighter">
                <i class="ace-icon fa fa-key"></i>
                OAuth2 / OIDC Identities
            </h4>
        </div>
        <div class="widget-body">
            <div class="widget-main no-padding">
                <div class="table-responsive">
                    <table class="table table-bordered table-condensed table-striped">
                        <thead>
                            <tr>
                                <th><?php echo lang_get( 'username' ) ?></th>
                                <th>Provider</th>
                                <th>Email</th>
                                <th>Subject (sub)</th>
                                <th>Linked</th>
                                <th>Last used</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach( $t_identities as $t_row ): ?>
                            <?php
                                $t_user_id  = (int) $t_row['user_id'];
                                $t_username = user_exists( $t_user_id )
                                    ? string_display_line( user_get_name( $t_user_id ) )
                                    : '<em>deleted user #' . $t_user_id . '</em>';
                            ?>
                            <tr>
                                <td><?php echo $t_username ?></td>
                                <td><?php echo string_display_line( $t_row['provider'] ) ?></td>
                                <td><?php echo string_display_line( $t_row['email'] ) ?></td>
                                <td>
                                    <code style="font-size:11px;"><?php echo string_display_line( $t_row['subject'] ) ?></code>
                                </td>
                                <td><?php echo string_display_line( $t_row['linked_at'] ) ?></td>
                                <td><?php echo $t_row['last_used'] ? string_display_line( $t_row['last_used'] ) : '—' ?></td>
                                <td>
                                    <form method="post" action="<?php echo plugin_page( 'admin' ) ?>"
                                          onsubmit="return confirm('Unlink this identity?')">
                                        <?php echo form_security_field( 'imatic_oauth2_admin_unlink' ) ?>
                                        <input type="hidden" name="action"      value="unlink">
                                        <input type="hidden" name="identity_id" value="<?php echo (int) $t_row['id'] ?>">
                                        <input type="hidden" name="user_id"     value="<?php echo $t_user_id ?>">
                                        <button type="submit" class="btn btn-xs btn-danger btn-white btn-round">
                                            Unlink
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach ?>
                        <?php if( empty( $t_identities ) ): ?>
                            <tr>
                                <td colspan="7" class="center">No OAuth2 identities found.</td>
                            </tr>
                        <?php endif ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php layout_page_end() ?>
