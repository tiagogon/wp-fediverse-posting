<?php
/**
 * Mastodon (and every server speaking the Mastodon client API): the network
 * object around the OAuth and API traits. The traits hold the HTTP code; this
 * class gives them their connection (get_instance / get_token) and maps the
 * generic contract (limits, publish, delete) onto them.
 *
 * Stored under settings()['networks']['mastodon']: instance, access_token,
 * account. wp-config constants FEDIVERSE_POSTING_INSTANCE / _TOKEN win.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Fediverse_Network_Mastodon extends Fediverse_Network {

    use Fediverse_OAuth_Trait;
    use Fediverse_Mastodon_API_Trait;

    public function id() {
        return 'mastodon';
    }

    public function label() {
        return 'Mastodon';
    }

    public function register_hooks() {
        add_action( 'admin_post_fediverse_connect', array( $this, 'handle_connect' ) );
        add_action( 'admin_post_fediverse_oauth_callback', array( $this, 'handle_oauth_callback' ) );
        add_action( 'admin_post_fediverse_save_token', array( $this, 'handle_save_token' ) );
        add_action( 'admin_post_fediverse_disconnect', array( $this, 'handle_disconnect' ) );
    }


    // =====================================================================
    //  CONNECTION
    // =====================================================================

    /** Server host (e.g. "ciberlandia.pt"); wp-config constant wins. */
    public function get_instance() {
        if ( defined( 'FEDIVERSE_POSTING_INSTANCE' ) && FEDIVERSE_POSTING_INSTANCE ) {
            return self::normalize_instance( FEDIVERSE_POSTING_INSTANCE );
        }
        return (string) $this->settings()['instance'];
    }

    /** Access token; wp-config constant wins. */
    public function get_token() {
        if ( defined( 'FEDIVERSE_POSTING_TOKEN' ) && FEDIVERSE_POSTING_TOKEN ) {
            return (string) FEDIVERSE_POSTING_TOKEN;
        }
        return (string) $this->settings()['access_token'];
    }

    public function uses_constants() {
        return defined( 'FEDIVERSE_POSTING_TOKEN' ) && FEDIVERSE_POSTING_TOKEN;
    }

    public function is_connected() {
        return '' !== $this->get_instance() && '' !== $this->get_token();
    }

    public function account() {
        $account = $this->settings()['account'];
        return is_array( $account ) ? $account : array();
    }

    public function disconnect() {
        $this->revoke_token();
        $this->update_settings( array( 'access_token' => '', 'account' => array() ) );
    }

    public function connection_notice_messages() {
        return array(
            'connected'     => __( 'Connected to Mastodon. You can now share posts.', 'wp-fediverse-posting' ),
            'disconnected'  => __( 'Disconnected from Mastodon. The access token was revoked.', 'wp-fediverse-posting' ),
            'bad_instance'  => __( 'That does not look like a server name. Type it like ciberlandia.pt or mastodon.social.', 'wp-fediverse-posting' ),
            'app_failed'    => __( 'Could not register with that server. Is the name right, and does it run Mastodon or a compatible server?', 'wp-fediverse-posting' ),
            'denied'        => __( 'Access was not approved on the server.', 'wp-fediverse-posting' ),
            'bad_state'     => __( 'The connection expired or did not start here. Press Connect again.', 'wp-fediverse-posting' ),
            'token_failed'  => __( 'The server did not hand over an access token. Press Connect again.', 'wp-fediverse-posting' ),
            'verify_failed' => __( 'The server did not accept the access token.', 'wp-fediverse-posting' ),
            'no_token'      => __( 'Paste an access token first.', 'wp-fediverse-posting' ),
        );
    }


    // =====================================================================
    //  CONTRACT
    // =====================================================================

    public function limits() {
        return $this->instance_limits() + array(
            'alt_max'    => 1500,
            'hard_limit' => true,
        );
    }

    /** Mastodon's counting: every URL is worth url_length characters. */
    public function count_characters( $text ) {
        $limits = $this->instance_limits();
        $text   = preg_replace( '#https?://\S+#u', str_repeat( 'x', (int) $limits['url_length'] ), (string) $text );
        return mb_strlen( $text );
    }

    public function supports( $feature ) {
        return in_array( $feature, array( 'visibility', 'spoiler_text', 'server_link_card' ), true );
    }

    /**
     * Upload every image (all or nothing), then create the status. The
     * Idempotency-Key is the row id with NO attempt number, so a retry or a
     * stuck-row recovery within the server's hour returns the first post.
     */
    public function publish( array $row, $post, $text, array $files, array $context = array() ) {
        $uploaded = array();
        foreach ( $files as $file ) {
            $media_id = $this->upload_media( $file['path'], $file['mime'], $file['alt'] );
            if ( is_wp_error( $media_id ) ) {
                return $media_id;
            }
            $uploaded[] = $media_id;
        }

        $params = array(
            'status'     => $text,
            'visibility' => $row['visibility'],
        );
        $language = $this->plugin->resolve_language( $row['language'], $post );
        if ( '' !== $language ) {
            $params['language'] = $language;
        }
        if ( '' !== $row['spoiler_text'] ) {
            $params['spoiler_text'] = $row['spoiler_text'];
        }
        if ( $uploaded ) {
            $params['media_ids'] = $uploaded;
            $params['sensitive'] = ( $row['is_sensitive'] || '' !== $row['spoiler_text'] );
        }
        /** The JSON sent to /api/v1/statuses. */
        $params = apply_filters( 'fediverse_posting_status_params', $params, $row, $post );

        $result = $this->post_status( $params, Fediverse_Queue::idempotency_key( $row['id'] ) );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $result['network'] = $this->id();
        return $result;
    }

    public function delete( $remote_id ) {
        return $this->delete_status( $remote_id );
    }
}
