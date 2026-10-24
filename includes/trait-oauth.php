<?php
/**
 * OAuth trait: connect a site to an account on any Mastodon-API server.
 *
 * Flow: Settings → "Connect" posts the server name to admin-post.php
 * (fediverse_connect). We register this site as an app on that server
 * (POST /api/v1/apps, once per server + redirect URI, kept in APPS_OPTION),
 * then send the user to /oauth/authorize with a one-time `state`. The server
 * redirects back to admin-post.php?action=fediverse_oauth_callback, where the
 * code is exchanged at /oauth/token and the account checked with
 * verify_credentials. Mastodon tokens do not expire: no refresh cron.
 *
 * Fallback: paste an access token (fediverse_save_token), checked the same way.
 *
 * Used by Fediverse_Posting. Uses: api_request_raw(), verify_credentials(),
 * get_instance(), get_token(), update_settings(), log().
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait Fediverse_OAuth_Trait {

    public static function oauth_scopes() {
        return 'read:accounts write:statuses write:media';
    }

    public static function oauth_redirect_uri() {
        return admin_url( 'admin-post.php?action=fediverse_oauth_callback' );
    }

    public static function settings_url( $args = array() ) {
        return add_query_arg( $args, admin_url( 'admin.php?page=fediverse-posting-settings' ) );
    }

    /**
     * "https://Ciberlandia.pt/@tiago" → "ciberlandia.pt". Also accepts
     * "@tiago@ciberlandia.pt". Returns '' when nothing usable is left.
     */
    public static function normalize_instance( $input ) {
        $input = trim( (string) $input );
        if ( preg_match( '/^@?[^@\s]+@([^@\s\/]+)$/', $input, $m ) ) {
            $input = $m[1];
        }
        $input = preg_replace( '#^[a-z]+://#i', '', $input );
        $input = preg_replace( '#[/?\#].*$#', '', $input );
        $input = strtolower( $input );
        return preg_match( '/^[a-z0-9.-]+(:\d+)?$/', $input ) && false !== strpos( $input, '.' ) ? $input : '';
    }

    private function redirect_with_notice( $code, $type = 'error' ) {
        wp_safe_redirect( self::settings_url( array( 'fedi_notice' => $code, 'fedi_type' => $type ) ) );
        exit;
    }

    private function require_settings_cap( $nonce_action ) {
        if ( ! current_user_can( self::settings_capability() ) ) {
            wp_die( esc_html__( 'You are not allowed to change these settings.', 'wp-fediverse-posting' ), 403 );
        }
        check_admin_referer( $nonce_action );
    }

    /**
     * Get (or register) this site's app on $instance. Re-registers when the
     * redirect URI changed (site moved), since servers match it exactly.
     *
     * @return array|WP_Error {client_id, client_secret}
     */
    private function get_or_register_app( $instance ) {
        $apps     = get_option( self::APPS_OPTION, array() );
        $redirect = self::oauth_redirect_uri();

        if ( isset( $apps[ $instance ]['client_id'], $apps[ $instance ]['redirect'] ) && $apps[ $instance ]['redirect'] === $redirect ) {
            return $apps[ $instance ];
        }

        $res = $this->api_request_raw( $instance, 'POST', '/api/v1/apps', array(
            'body' => array(
                'client_name'   => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ?: 'WordPress',
                'redirect_uris' => $redirect,
                'scopes'        => self::oauth_scopes(),
                'website'       => home_url( '/' ),
            ),
        ) );
        if ( is_wp_error( $res ) ) {
            return $res;
        }
        if ( empty( $res['body']['client_id'] ) || empty( $res['body']['client_secret'] ) ) {
            return new WP_Error( 'fediverse_app', __( 'The server did not return app credentials.', 'wp-fediverse-posting' ) );
        }

        $apps                = is_array( $apps ) ? $apps : array();
        $apps[ $instance ] = array(
            'client_id'     => (string) $res['body']['client_id'],
            'client_secret' => (string) $res['body']['client_secret'],
            'redirect'      => $redirect,
        );
        update_option( self::APPS_OPTION, $apps, false );
        $this->log( "Registered app on {$instance}.", 'INFO' );
        return $apps[ $instance ];
    }

    /** admin-post: fediverse_connect. */
    public function handle_connect() {
        $this->require_settings_cap( 'fediverse_connect' );

        $instance = self::normalize_instance( isset( $_POST['instance'] ) ? wp_unslash( $_POST['instance'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalized above.
        if ( '' === $instance ) {
            $this->redirect_with_notice( 'bad_instance' );
        }

        $app = $this->get_or_register_app( $instance );
        if ( is_wp_error( $app ) ) {
            $this->log( 'Connect failed on ' . $instance . ': ' . $app->get_error_message(), 'ERROR' );
            set_transient( 'fediverse_notice_detail_' . get_current_user_id(), $app->get_error_message(), 300 );
            $this->redirect_with_notice( 'app_failed' );
        }

        $state = wp_generate_password( 32, false );
        set_transient( 'fediverse_oauth_' . get_current_user_id(), array( 'state' => $state, 'instance' => $instance ), 15 * MINUTE_IN_SECONDS );

        $url = 'https://' . $instance . '/oauth/authorize?' . http_build_query( array(
            'client_id'     => $app['client_id'],
            'redirect_uri'  => self::oauth_redirect_uri(),
            'response_type' => 'code',
            'scope'         => self::oauth_scopes(),
            'state'         => $state,
        ), '', '&', PHP_QUERY_RFC3986 );

        // External host chosen by the admin: wp_redirect, not wp_safe_redirect.
        wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
        exit;
    }

    /** admin-post: fediverse_oauth_callback (the server sends the user back here). */
    public function handle_oauth_callback() {
        if ( ! current_user_can( self::settings_capability() ) ) {
            wp_die( esc_html__( 'You are not allowed to change these settings.', 'wp-fediverse-posting' ), 403 );
        }

        $key     = 'fediverse_oauth_' . get_current_user_id();
        $pending = get_transient( $key );
        delete_transient( $key );

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- the one-time `state` is the CSRF check here.
        $state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
        $code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
        $error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
        // phpcs:enable

        if ( $error ) {
            $this->redirect_with_notice( 'denied' );
        }
        if ( ! is_array( $pending ) || ! hash_equals( (string) $pending['state'], $state ) || '' === $code ) {
            $this->redirect_with_notice( 'bad_state' );
        }

        $instance = $pending['instance'];
        $apps     = get_option( self::APPS_OPTION, array() );
        if ( empty( $apps[ $instance ]['client_id'] ) ) {
            $this->redirect_with_notice( 'app_failed' );
        }

        $res = $this->api_request_raw( $instance, 'POST', '/oauth/token', array(
            'body' => array(
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'client_id'     => $apps[ $instance ]['client_id'],
                'client_secret' => $apps[ $instance ]['client_secret'],
                'redirect_uri'  => self::oauth_redirect_uri(),
                'scope'         => self::oauth_scopes(),
            ),
        ) );
        if ( is_wp_error( $res ) || empty( $res['body']['access_token'] ) ) {
            $msg = is_wp_error( $res ) ? $res->get_error_message() : 'no access_token';
            $this->log( 'Token exchange failed on ' . $instance . ': ' . $msg, 'ERROR' );
            set_transient( 'fediverse_notice_detail_' . get_current_user_id(), $msg, 300 );
            $this->redirect_with_notice( 'token_failed' );
        }

        $this->finish_connection( $instance, (string) $res['body']['access_token'] );
    }

    /** admin-post: fediverse_save_token (manual fallback). */
    public function handle_save_token() {
        $this->require_settings_cap( 'fediverse_save_token' );

        $instance = self::normalize_instance( isset( $_POST['instance'] ) ? wp_unslash( $_POST['instance'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalized above.
        $token    = isset( $_POST['access_token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['access_token'] ) ) ) : '';
        if ( '' === $instance ) {
            $this->redirect_with_notice( 'bad_instance' );
        }
        if ( '' === $token ) {
            $this->redirect_with_notice( 'no_token' );
        }
        $this->finish_connection( $instance, $token );
    }

    /** Check the token against the server, then store instance + token + account. */
    private function finish_connection( $instance, $token ) {
        $account = $this->verify_credentials( $instance, $token );
        if ( is_wp_error( $account ) ) {
            $this->log( 'verify_credentials failed on ' . $instance . ': ' . $account->get_error_message(), 'ERROR' );
            set_transient( 'fediverse_notice_detail_' . get_current_user_id(), $account->get_error_message(), 300 );
            $this->redirect_with_notice( 'verify_failed' );
        }

        self::update_settings( array(
            'instance'     => $instance,
            'access_token' => $token,
            'account'      => $account,
        ) );
        delete_transient( 'fediverse_instance_limits_' . md5( $instance ) );
        $this->log( 'Connected as @' . $account['acct'] . ' on ' . $instance . '.', 'INFO' );
        $this->redirect_with_notice( 'connected', 'success' );
    }

    /** admin-post: fediverse_disconnect. Revokes the token (best effort) and forgets it. */
    public function handle_disconnect() {
        $this->require_settings_cap( 'fediverse_disconnect' );
        $this->revoke_token();
        self::update_settings( array( 'access_token' => '', 'account' => array() ) );
        $this->redirect_with_notice( 'disconnected', 'success' );
    }

    /** Revoke the stored token on the server. Used by Disconnect and uninstall. */
    public function revoke_token() {
        $instance = (string) $this->get_setting( 'instance' );
        $token    = (string) $this->get_setting( 'access_token' );
        $apps     = get_option( self::APPS_OPTION, array() );
        if ( '' === $instance || '' === $token || empty( $apps[ $instance ]['client_id'] ) ) {
            return;
        }
        $this->api_request_raw( $instance, 'POST', '/oauth/revoke', array(
            'body' => array(
                'client_id'     => $apps[ $instance ]['client_id'],
                'client_secret' => $apps[ $instance ]['client_secret'],
                'token'         => $token,
            ),
        ) );
    }
}
