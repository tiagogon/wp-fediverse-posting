<?php
/**
 * Bluesky sessions: how the plugin gets and keeps an access token for the
 * user's PDS. The network class only sees the interface, so an OAuth session
 * (DPoP-bound tokens) can be added later without touching the posting code.
 *
 * Fediverse_Bluesky_App_Password_Session: handle + app password →
 * com.atproto.server.createSession. The access JWT lives about two hours and
 * is renewed with refreshSession (both JWTs rotate); when that fails the
 * stored app password logs in again, so the connection never silently dies.
 *
 * Session state (did, pds_url, jwts, account) lives in its own option,
 * fediverse_posting_bluesky_session, never in the settings option: the
 * Settings form rewrites that one wholesale and would clobber a token the
 * cron refreshed in between. Credentials (handle, app password, pds host)
 * stay in the settings under networks['bluesky'].
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface Fediverse_Bluesky_Session {
    public function is_connected();
    public function did();
    public function handle();
    /** 'https://…' without trailing slash. */
    public function pds_url();
    /** @return array { acct, display_name, avatar, url } */
    public function account();
    public function set_account( array $account );
    /** Make sure a usable access token exists. @return true|WP_Error */
    public function ensure_fresh();
    /** Add auth to a wp_remote_request() args array. */
    public function sign( $method, $url, array $request );
    /** The PDS rejected auth. @return bool true = refreshed, retry the request once. */
    public function on_auth_error( $http_code, $error, $headers );
    /** Forget everything (disconnect / uninstall). */
    public function clear();
}

class Fediverse_Bluesky_App_Password_Session implements Fediverse_Bluesky_Session {

    const OPTION = 'fediverse_posting_bluesky_session';

    /** @var Fediverse_Network_Bluesky */
    private $network;

    /** @var array */
    private $state;

    public function __construct( Fediverse_Network_Bluesky $network ) {
        $this->network = $network;
        $this->state   = self::read();
    }

    private static function defaults() {
        return array(
            'did'         => '',
            'handle'      => '',
            'pds_url'     => '',
            'access_jwt'  => '',
            'refresh_jwt' => '',
            'access_exp'  => 0,
            'account'     => array(),
        );
    }

    private static function read() {
        $saved = get_option( self::OPTION, array() );
        return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
    }

    /** Re-read before writing: another request may have refreshed meanwhile. */
    private function store( array $changes ) {
        $this->state = array_merge( self::read(), $changes );
        if ( null === get_option( self::OPTION, null ) ) {
            add_option( self::OPTION, $this->state, '', false );
        } else {
            update_option( self::OPTION, $this->state, false );
        }
    }


    // =====================================================================
    //  INTERFACE
    // =====================================================================

    public function is_connected() {
        $creds = $this->network->credentials();
        return '' !== $creds['handle'] && '' !== $creds['app_password'];
    }

    public function did() {
        return (string) $this->state['did'];
    }

    public function handle() {
        return (string) $this->state['handle'];
    }

    public function pds_url() {
        $url = (string) $this->state['pds_url'];
        if ( '' === $url ) {
            $creds = $this->network->credentials();
            $url   = '' !== $creds['pds'] ? 'https://' . $creds['pds'] : Fediverse_Network_Bluesky::DEFAULT_ENTRY;
        }
        return untrailingslashit( $url );
    }

    public function account() {
        return is_array( $this->state['account'] ) ? $this->state['account'] : array();
    }

    public function set_account( array $account ) {
        $this->store( array( 'account' => $account ) );
    }

    public function ensure_fresh() {
        if ( ! $this->is_connected() ) {
            return new WP_Error( 'bluesky_not_connected', __( 'Bluesky is not connected.', 'wp-fediverse-posting' ), array( 'status' => 0, 'retryable' => false ) );
        }
        if ( '' === $this->did() || '' === (string) $this->state['refresh_jwt'] ) {
            return $this->relogin();
        }
        if ( (int) $this->state['access_exp'] - 60 > time() ) {
            return true;
        }
        $refreshed = $this->refresh();
        if ( ! is_wp_error( $refreshed ) ) {
            return true;
        }
        $data = (array) $refreshed->get_error_data();
        if ( ! empty( $data['retryable'] ) ) {
            return $refreshed; // Network trouble: do not burn a login on it.
        }
        $this->network->log( 'Refresh failed (' . $refreshed->get_error_message() . '), logging in again.', 'INFO' );
        return $this->relogin();
    }

    public function sign( $method, $url, array $request ) {
        $request['headers']['Authorization'] = 'Bearer ' . $this->state['access_jwt'];
        return $request;
    }

    public function on_auth_error( $http_code, $error, $headers ) {
        if ( in_array( $error, array( 'ExpiredToken', 'InvalidToken' ), true ) ) {
            $this->store( array( 'access_exp' => 0 ) );
            return true;
        }
        return false;
    }

    public function clear() {
        delete_option( self::OPTION );
        $this->state = self::defaults();
    }


    // =====================================================================
    //  LOGIN / REFRESH
    // =====================================================================

    /**
     * First login from the Connect form: creates the session, resolves the
     * real PDS from the DID document and stores everything.
     *
     * @return array|WP_Error The stored state.
     */
    public static function create( Fediverse_Network_Bluesky $network, $identifier, $password, $pds_host = '' ) {
        $entry = '' !== $pds_host ? 'https://' . $pds_host : Fediverse_Network_Bluesky::DEFAULT_ENTRY;
        $body  = self::login( $network, $entry, $identifier, $password );
        if ( is_wp_error( $body ) ) {
            return $body;
        }
        $session = new self( $network );
        $session->store( self::state_from( $body, $entry ) );
        return $session->state;
    }

    private function relogin() {
        $creds = $this->network->credentials();
        $entry = '' !== $this->state['pds_url'] ? $this->state['pds_url'] : ( '' !== $creds['pds'] ? 'https://' . $creds['pds'] : Fediverse_Network_Bluesky::DEFAULT_ENTRY );
        $body  = self::login( $this->network, $entry, $creds['handle'], $creds['app_password'] );
        if ( is_wp_error( $body ) ) {
            return $body;
        }
        $this->store( self::state_from( $body, $entry ) );
        $this->network->log( 'Logged in as ' . $this->state['handle'] . '.', 'INFO' );
        return true;
    }

    private function refresh() {
        $res = Fediverse_Network_Bluesky::xrpc_raw( $this->pds_url(), 'POST', 'com.atproto.server.refreshSession', array(
            'token'   => (string) $this->state['refresh_jwt'],
            'timeout' => 20,
        ) );
        if ( is_wp_error( $res ) ) {
            return $res;
        }
        $this->store( self::state_from( $res['body'], $this->pds_url() ) );
        $this->network->log( 'Session refreshed.', 'DEBUG' );
        return true;
    }

    /** @return array|WP_Error createSession body. */
    private static function login( Fediverse_Network_Bluesky $network, $entry, $identifier, $password ) {
        $res = Fediverse_Network_Bluesky::xrpc_raw( $entry, 'POST', 'com.atproto.server.createSession', array(
            'json'    => array( 'identifier' => $identifier, 'password' => $password ),
            'timeout' => 20,
        ) );
        if ( is_wp_error( $res ) ) {
            $data = (array) $res->get_error_data();
            // A wrong password is final, not something to retry every 5 minutes.
            if ( isset( $data['status'] ) && in_array( (int) $data['status'], array( 400, 401 ), true ) ) {
                $data['retryable'] = false;
                return new WP_Error( 'bluesky_login', $res->get_error_message(), $data );
            }
            return $res;
        }
        $body = is_array( $res['body'] ) ? $res['body'] : array();
        if ( empty( $body['accessJwt'] ) || empty( $body['did'] ) ) {
            return new WP_Error( 'bluesky_login', __( 'Bluesky did not return a session.', 'wp-fediverse-posting' ), array( 'status' => 0, 'retryable' => false ) );
        }
        return $body;
    }

    /** Session fields from a createSession / refreshSession body. */
    private static function state_from( $body, $entry ) {
        $body  = is_array( $body ) ? $body : array();
        $state = array(
            'access_jwt'  => isset( $body['accessJwt'] ) ? (string) $body['accessJwt'] : '',
            'refresh_jwt' => isset( $body['refreshJwt'] ) ? (string) $body['refreshJwt'] : '',
        );
        $state['access_exp'] = self::jwt_exp( $state['access_jwt'] );
        if ( ! empty( $body['did'] ) ) {
            $state['did'] = (string) $body['did'];
        }
        if ( ! empty( $body['handle'] ) ) {
            $state['handle'] = (string) $body['handle'];
        }
        $pds = self::pds_from_did_doc( isset( $body['didDoc'] ) ? $body['didDoc'] : null );
        if ( '' !== $pds ) {
            $state['pds_url'] = $pds;
        } elseif ( '' !== $entry ) {
            $state['pds_url'] = untrailingslashit( $entry );
        }
        return $state;
    }

    /** The PDS endpoint from a DID document, '' when absent. */
    private static function pds_from_did_doc( $doc ) {
        if ( ! is_array( $doc ) || empty( $doc['service'] ) || ! is_array( $doc['service'] ) ) {
            return '';
        }
        foreach ( $doc['service'] as $service ) {
            if ( isset( $service['type'], $service['serviceEndpoint'] ) && 'AtprotoPersonalDataServer' === $service['type'] && is_string( $service['serviceEndpoint'] ) ) {
                return untrailingslashit( esc_url_raw( $service['serviceEndpoint'] ) );
            }
        }
        return '';
    }

    /** The `exp` claim of a JWT (no signature check needed: it is only used to know when to refresh). */
    private static function jwt_exp( $jwt ) {
        $parts = explode( '.', (string) $jwt );
        if ( count( $parts ) < 2 ) {
            return 0;
        }
        $payload = json_decode( base64_decode( strtr( $parts[1], '-_', '+/' ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
        return is_array( $payload ) && isset( $payload['exp'] ) ? (int) $payload['exp'] : 0;
    }
}
