<?php
/**
 * Bluesky (AT Protocol): the only code that talks to a PDS.
 *
 * - Posts are app.bsky.feed.post records created with
 *   com.atproto.repo.createRecord. Text is limited to 300 graphemes (and
 *   3000 bytes); links and #hashtags only become clickable through facets
 *   with UTF-8 byte offsets (Fediverse_Bluesky_Text).
 * - Images go up as blobs (uploadBlob, ≤ 1 MB each, max 4) and are embedded
 *   as app.bsky.embed.images, with the link clickable in the text. Link posts
 *   without an image get an app.bsky.embed.external card that the plugin
 *   builds itself (Bluesky fetches no previews). The two embeds cannot be
 *   combined.
 * - "Mark images as sensitive" becomes a self-label (sexual / nudity / porn /
 *   graphic-media) chosen per row.
 * - No idempotency key exists: the queue's claim plus the target update right
 *   after createRecord are the protection; a target sent again after a
 *   failure or a stuck-row recovery first looks for an identical recent post.
 *
 * Errors are WP_Error 'bluesky_api' with data { status, retryable, retry_at, error }.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Fediverse_Network_Bluesky extends Fediverse_Network {

    const COLLECTION    = 'app.bsky.feed.post';
    const DEFAULT_ENTRY = 'https://bsky.social';
    const MAX_GRAPHEMES = 300;
    const MAX_BYTES     = 3000;
    const MAX_BLOB      = 1000000;

    /** @var Fediverse_Bluesky_Session|null */
    private $session;

    public function id() {
        return 'bluesky';
    }

    public function label() {
        return 'Bluesky';
    }

    /** Self-label values Bluesky understands for sensitive media. */
    public static function labels() {
        return array(
            'graphic-media' => __( 'Graphic media (violence, gore)', 'wp-fediverse-posting' ),
            'nudity'        => __( 'Nudity (non-sexual)', 'wp-fediverse-posting' ),
            'sexual'        => __( 'Sexually suggestive', 'wp-fediverse-posting' ),
            'porn'          => __( 'Adult content', 'wp-fediverse-posting' ),
        );
    }

    public function register_hooks() {
        add_action( 'admin_post_fediverse_bluesky_connect', array( $this, 'handle_connect' ) );
        add_action( 'admin_post_fediverse_bluesky_disconnect', array( $this, 'handle_disconnect' ) );
    }


    // =====================================================================
    //  CONNECTION
    // =====================================================================

    /** @return array { handle, app_password, pds } — wp-config constants win. */
    public function credentials() {
        $settings = $this->settings();
        $creds    = array(
            'handle'       => (string) $settings['handle'],
            'app_password' => (string) $settings['app_password'],
            'pds'          => (string) $settings['pds'],
        );
        if ( $this->uses_constants() ) {
            $creds['handle']       = self::normalize_handle( FEDIVERSE_POSTING_BLUESKY_HANDLE );
            $creds['app_password'] = (string) FEDIVERSE_POSTING_BLUESKY_APP_PASSWORD;
            if ( defined( 'FEDIVERSE_POSTING_BLUESKY_PDS' ) && FEDIVERSE_POSTING_BLUESKY_PDS ) {
                $creds['pds'] = self::normalize_host( FEDIVERSE_POSTING_BLUESKY_PDS );
            }
        }
        return $creds;
    }

    public function uses_constants() {
        return defined( 'FEDIVERSE_POSTING_BLUESKY_HANDLE' ) && FEDIVERSE_POSTING_BLUESKY_HANDLE
            && defined( 'FEDIVERSE_POSTING_BLUESKY_APP_PASSWORD' ) && FEDIVERSE_POSTING_BLUESKY_APP_PASSWORD;
    }

    public function is_connected() {
        return $this->session()->is_connected();
    }

    public function account() {
        return $this->session()->account();
    }

    /** @return Fediverse_Bluesky_Session */
    public function session() {
        if ( ! $this->session ) {
            // 'method' picks the implementation; only app_password exists today.
            $this->session = new Fediverse_Bluesky_App_Password_Session( $this );
        }
        return $this->session;
    }

    /** "@you.bsky.social" → "you.bsky.social". */
    public static function normalize_handle( $input ) {
        $input = strtolower( trim( (string) $input ) );
        $input = ltrim( $input, '@' );
        $input = preg_replace( '#^[a-z]+://#', '', $input );
        return preg_match( '/^[a-z0-9.-]+$/', $input ) && false !== strpos( $input, '.' ) ? $input : '';
    }

    /** "https://pds.example.com/" → "pds.example.com". */
    public static function normalize_host( $input ) {
        $input = strtolower( trim( (string) $input ) );
        $input = preg_replace( '#^[a-z]+://#', '', $input );
        $input = preg_replace( '#[/?\#].*$#', '', $input );
        return preg_match( '/^[a-z0-9.-]+(:\d+)?$/', $input ) && false !== strpos( $input, '.' ) ? $input : '';
    }

    /** admin-post: fediverse_bluesky_connect. */
    public function handle_connect() {
        $this->require_settings_cap( 'fediverse_bluesky_connect' );

        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalized / password kept as typed.
        $handle   = self::normalize_handle( isset( $_POST['handle'] ) ? wp_unslash( $_POST['handle'] ) : '' );
        $password = isset( $_POST['app_password'] ) ? trim( (string) wp_unslash( $_POST['app_password'] ) ) : '';
        $pds      = self::normalize_host( isset( $_POST['pds'] ) ? wp_unslash( $_POST['pds'] ) : '' );
        // phpcs:enable

        if ( '' === $handle ) {
            $this->redirect_with_notice( 'bluesky_bad_handle' );
        }
        if ( '' === $password ) {
            $this->redirect_with_notice( 'bluesky_no_password' );
        }

        $state = Fediverse_Bluesky_App_Password_Session::create( $this, $handle, $password, $pds );
        if ( is_wp_error( $state ) ) {
            $this->log( 'Login failed for ' . $handle . ': ' . $state->get_error_message(), 'ERROR' );
            $this->remember_notice_detail( $state->get_error_message() );
            $this->redirect_with_notice( 'bluesky_login_failed' );
        }

        $this->update_settings( array( 'handle' => $handle, 'app_password' => $password, 'pds' => $pds ) );
        $this->session = null; // Re-read the stored state.

        $profile = $this->profile();
        if ( ! is_wp_error( $profile ) ) {
            $this->session()->set_account( $profile );
        }
        $this->log( 'Connected as ' . $handle . ' (' . $state['did'] . ').', 'INFO' );
        $this->redirect_with_notice( 'bluesky_connected', 'success' );
    }

    /** admin-post: fediverse_bluesky_disconnect. */
    public function handle_disconnect() {
        $this->require_settings_cap( 'fediverse_bluesky_disconnect' );
        $this->disconnect();
        $this->redirect_with_notice( 'bluesky_disconnected', 'success' );
    }

    /** End the session on the PDS (best effort), forget the password and the session. */
    public function disconnect() {
        $state = get_option( Fediverse_Bluesky_App_Password_Session::OPTION, array() );
        if ( is_array( $state ) && ! empty( $state['refresh_jwt'] ) && ! empty( $state['pds_url'] ) ) {
            self::xrpc_raw( $state['pds_url'], 'POST', 'com.atproto.server.deleteSession', array( 'token' => $state['refresh_jwt'], 'timeout' => 10 ) );
        }
        $this->session()->clear();
        $this->update_settings( array( 'app_password' => '' ) );
    }

    public function connection_notice_messages() {
        return array(
            'bluesky_connected'    => __( 'Connected to Bluesky. You can now share posts there.', 'wp-fediverse-posting' ),
            'bluesky_disconnected' => __( 'Disconnected from Bluesky. The session was ended and the app password forgotten.', 'wp-fediverse-posting' ),
            'bluesky_bad_handle'   => __( 'That does not look like a Bluesky handle. Type it like you.bsky.social.', 'wp-fediverse-posting' ),
            'bluesky_no_password'  => __( 'Paste an app password first.', 'wp-fediverse-posting' ),
            'bluesky_login_failed' => __( 'Bluesky did not accept the handle and app password.', 'wp-fediverse-posting' ),
        );
    }

    /** The connected account's profile, in the shape every network uses. */
    public function profile() {
        $did = $this->session()->did();
        $res = $this->xrpc( 'GET', 'app.bsky.actor.getProfile', array( 'query' => array( 'actor' => $did ), 'timeout' => 15 ) );
        if ( is_wp_error( $res ) ) {
            return $res;
        }
        $p      = is_array( $res['body'] ) ? $res['body'] : array();
        $handle = isset( $p['handle'] ) ? (string) $p['handle'] : $this->session()->handle();
        return array(
            'acct'         => $handle,
            'display_name' => isset( $p['displayName'] ) && '' !== $p['displayName'] ? (string) $p['displayName'] : $handle,
            'avatar'       => isset( $p['avatar'] ) ? esc_url_raw( $p['avatar'] ) : '',
            'url'          => 'https://bsky.app/profile/' . $did,
        );
    }


    // =====================================================================
    //  CONTRACT
    // =====================================================================

    public function limits() {
        return array(
            'max_characters'   => self::MAX_GRAPHEMES,
            'max_media'        => 4,
            'image_size_limit' => self::MAX_BLOB,
            'image_types'      => array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ),
            'alt_max'          => 2000,
            'url_length'       => null,
            'hard_limit'       => false,
        );
    }

    public function count_characters( $text ) {
        return Fediverse_Bluesky_Text::graphemes( $text );
    }

    public function truncate( $text, $max ) {
        return Fediverse_Bluesky_Text::truncate_words( $text, $max, array( $this, 'count_characters' ), true, self::MAX_BYTES );
    }

    public function supports( $feature ) {
        return 'labels' === $feature;
    }

    public function publish( array $row, $post, $text, array $files, array $context = array() ) {
        $fresh = $this->session()->ensure_fresh();
        if ( is_wp_error( $fresh ) ) {
            return $fresh;
        }
        $did = $this->session()->did();

        // Sent before and not recorded (crash, retry)? Reuse the post instead of doubling it.
        if ( ! empty( $context['attempts'] ) ) {
            $existing = $this->find_recent_duplicate( $text );
            if ( $existing ) {
                $this->log( 'Reusing the post already on Bluesky: ' . $existing['uri'], 'INFO' );
                return array( 'id' => $existing['uri'], 'url' => self::post_url( $did, $existing['uri'] ), 'network' => $this->id() );
            }
        }

        $blobs = array();
        foreach ( $files as $file ) {
            $blob = $this->upload_blob( $file['path'], $file['mime'] );
            if ( is_wp_error( $blob ) ) {
                return $blob;
            }
            $blobs[] = array( 'blob' => $blob, 'alt' => (string) $file['alt'], 'ratio' => self::aspect_ratio( $file['path'] ) );
        }

        $record = array(
            '$type'     => self::COLLECTION,
            'text'      => $text,
            'createdAt' => gmdate( 'Y-m-d\TH:i:s\Z' ),
        );
        $facets = Fediverse_Bluesky_Text::facets( $text );
        if ( $facets ) {
            $record['facets'] = $facets;
        }
        $language = $this->plugin->resolve_language( $row['language'], $post );
        if ( '' !== $language ) {
            $record['langs'] = array( $language );
        }
        $embed = $this->embed_for( $row, $post, $blobs );
        if ( $embed ) {
            $record['embed'] = $embed;
        }
        if ( ! empty( $row['is_sensitive'] ) ) {
            $label = isset( $row['sensitive_label'] ) && array_key_exists( $row['sensitive_label'], self::labels() ) ? $row['sensitive_label'] : (string) $this->plugin->get_setting( 'bluesky_sensitive_label' );
            if ( array_key_exists( $label, self::labels() ) ) {
                $record['labels'] = array(
                    '$type'  => 'com.atproto.label.defs#selfLabels',
                    'values' => array( array( 'val' => $label ) ),
                );
            }
        }
        /** The app.bsky.feed.post record about to be created. */
        $record = apply_filters( 'fediverse_posting_bluesky_record', $record, $row, $post );

        $res = $this->xrpc( 'POST', 'com.atproto.repo.createRecord', array(
            'json'    => array( 'repo' => $did, 'collection' => self::COLLECTION, 'record' => $record ),
            'timeout' => 60,
        ) );
        if ( is_wp_error( $res ) ) {
            return $res;
        }
        $body = is_array( $res['body'] ) ? $res['body'] : array();
        if ( empty( $body['uri'] ) ) {
            return new WP_Error( 'bluesky_record', __( 'Bluesky did not return the new post.', 'wp-fediverse-posting' ), array( 'status' => $res['code'], 'retryable' => false ) );
        }
        return array(
            'id'      => (string) $body['uri'],
            'url'     => self::post_url( $did, (string) $body['uri'] ),
            'cid'     => isset( $body['cid'] ) ? (string) $body['cid'] : '',
            'network' => $this->id(),
        );
    }

    /**
     * Any row with images sends them as images (a post carries one embed, so
     * no card then); a post's link row without images gets a link card;
     * nothing for text-only.
     */
    private function embed_for( array $row, $post, array $blobs ) {
        $type = $row['content_type'];
        if ( $blobs && ( 'gallery' === $type || 'link_image' === $type || ! $post ) ) {
            $images = array();
            foreach ( array_slice( $blobs, 0, 4 ) as $b ) {
                $image = array( 'image' => $b['blob'], 'alt' => $b['alt'] );
                if ( ! empty( $b['ratio'] ) ) {
                    $image['aspectRatio'] = $b['ratio'];
                }
                $images[] = $image;
            }
            return array( '$type' => 'app.bsky.embed.images', 'images' => $images );
        }
        if ( $post && in_array( $type, array( 'link', 'link_image', 'gallery' ), true ) ) {
            $external = array(
                'uri'         => (string) get_permalink( $post ),
                'title'       => wp_specialchars_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES ),
                'description' => Fediverse_Bluesky_Text::substr( Fediverse_Posting::excerpt_for( $post ), 0, self::MAX_GRAPHEMES ),
            );
            return array( '$type' => 'app.bsky.embed.external', 'external' => $external );
        }
        return null;
    }

    /**
     * { width, height } as displayed, or null when unknown (the key is optional
     * on Bluesky, so an unreadable size never blocks a post). Reads headers only.
     * JPEG dimensions ignore EXIF rotation, so orientations 5 to 8 swap them.
     */
    private static function aspect_ratio( $path ) {
        $size = wp_getimagesize( $path );
        if ( ! $size || empty( $size[0] ) || empty( $size[1] ) ) {
            return null;
        }
        $width  = (int) $size[0];
        $height = (int) $size[1];
        if ( IMAGETYPE_JPEG === $size[2] && function_exists( 'exif_read_data' ) ) {
            $exif = @exif_read_data( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- corrupt EXIF warns; it only means no rotation is known.
            if ( ! empty( $exif['Orientation'] ) && (int) $exif['Orientation'] >= 5 && (int) $exif['Orientation'] <= 8 ) {
                list( $width, $height ) = array( $height, $width );
            }
        }
        return array( 'width' => $width, 'height' => $height );
    }

    public function delete( $remote_id ) {
        if ( ! preg_match( '#^at://([^/]+)/([^/]+)/([^/]+)$#', (string) $remote_id, $m ) ) {
            return new WP_Error( 'bluesky_uri', __( 'That is not a Bluesky post URI.', 'wp-fediverse-posting' ) );
        }
        $res = $this->xrpc( 'POST', 'com.atproto.repo.deleteRecord', array(
            'json' => array( 'repo' => $m[1], 'collection' => $m[2], 'rkey' => $m[3] ),
        ) );
        if ( is_wp_error( $res ) ) {
            $data = (array) $res->get_error_data();
            if ( isset( $data['error'] ) && 'RecordNotFound' === $data['error'] ) {
                return true;
            }
            return $res;
        }
        return true;
    }

    /** https://bsky.app/profile/<did>/post/<rkey> — the DID, so a handle change never breaks the link. */
    public static function post_url( $did, $uri ) {
        $rkey = substr( (string) $uri, strrpos( (string) $uri, '/' ) + 1 );
        return 'https://bsky.app/profile/' . $did . '/post/' . rawurlencode( $rkey );
    }

    /** The account's last posts: one with the same text created within an hour, or null. */
    private function find_recent_duplicate( $text ) {
        $res = $this->xrpc( 'GET', 'com.atproto.repo.listRecords', array(
            'query'   => array( 'repo' => $this->session()->did(), 'collection' => self::COLLECTION, 'limit' => 10 ),
            'timeout' => 15,
        ) );
        if ( is_wp_error( $res ) || ! is_array( $res['body'] ) || empty( $res['body']['records'] ) ) {
            return null;
        }
        foreach ( (array) $res['body']['records'] as $record ) {
            $value = isset( $record['value'] ) ? $record['value'] : array();
            if ( ! isset( $value['text'], $value['createdAt'], $record['uri'] ) || $value['text'] !== $text ) {
                continue;
            }
            $created = strtotime( $value['createdAt'] );
            if ( $created && time() - $created < HOUR_IN_SECONDS ) {
                return array( 'uri' => (string) $record['uri'] );
            }
        }
        return null;
    }


    // =====================================================================
    //  HTTP
    // =====================================================================

    /**
     * Low-level XRPC call against any PDS.
     *
     * @param string $base  'https://bsky.social'
     * @param string $nsid  'com.atproto.repo.createRecord'
     * @param array  $args {
     *     @type array  $query        GET parameters.
     *     @type array  $json         JSON body.
     *     @type string $raw          Pre-built body, with $content_type (blobs).
     *     @type string $content_type
     *     @type string $token        Bearer token.
     *     @type array  $headers
     *     @type int    $timeout      Seconds (default 30).
     * }
     * @return array|WP_Error { code, body (decoded JSON or raw), headers }
     */
    public static function xrpc_raw( $base, $method, $nsid, $args = array() ) {
        $url = untrailingslashit( $base ) . '/xrpc/' . $nsid;
        if ( ! empty( $args['query'] ) ) {
            $url = add_query_arg( array_map( 'strval', (array) $args['query'] ), $url );
        }
        $headers           = isset( $args['headers'] ) ? (array) $args['headers'] : array();
        $headers['Accept'] = 'application/json';
        if ( ! empty( $args['token'] ) ) {
            $headers['Authorization'] = 'Bearer ' . $args['token'];
        }
        $request = array(
            'method'     => $method,
            'timeout'    => isset( $args['timeout'] ) ? (int) $args['timeout'] : 30,
            'headers'    => $headers,
            'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; wp-fediverse-posting/' . FEDIVERSE_POSTING_VERSION . '; ' . home_url( '/' ),
        );
        if ( isset( $args['json'] ) ) {
            $request['headers']['Content-Type'] = 'application/json';
            $request['body']                    = wp_json_encode( $args['json'] );
        } elseif ( isset( $args['raw'] ) ) {
            $request['headers']['Content-Type'] = $args['content_type'];
            $request['body']                    = $args['raw'];
        }

        $response = wp_remote_request( $url, $request );
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'bluesky_http', $response->get_error_message(), array( 'status' => 0, 'retryable' => true ) );
        }

        $code    = (int) wp_remote_retrieve_response_code( $response );
        $raw     = wp_remote_retrieve_body( $response );
        $decoded = json_decode( $raw, true );
        $body    = ( null === $decoded ) ? $raw : $decoded;

        if ( $code < 200 || $code >= 300 ) {
            $error   = is_array( $body ) && ! empty( $body['error'] ) ? (string) $body['error'] : '';
            $message = is_array( $body ) && ! empty( $body['message'] ) ? (string) $body['message'] : ( $error ? $error : sprintf( 'HTTP %d', $code ) );
            if ( $error && $message !== $error ) {
                $message = $error . ': ' . $message;
            }
            $data = array( 'status' => $code, 'retryable' => ( 429 === $code || $code >= 500 ), 'error' => $error );
            if ( 429 === $code ) {
                $reset = wp_remote_retrieve_header( $response, 'ratelimit-reset' );
                if ( $reset && is_numeric( $reset ) ) {
                    $data['retry_at'] = (int) $reset;
                }
            }
            return new WP_Error( 'bluesky_api', $message, $data );
        }

        return array(
            'code'    => $code,
            'body'    => $body,
            'headers' => wp_remote_retrieve_headers( $response ),
        );
    }

    /**
     * Authenticated call against the connected PDS. Refreshes the session
     * first when needed and retries once after an expired-token answer.
     */
    public function xrpc( $method, $nsid, $args = array(), $retry = true ) {
        $session = $this->session();
        $fresh   = $session->ensure_fresh();
        if ( is_wp_error( $fresh ) ) {
            return $fresh;
        }
        $args    = $session->sign( $method, $nsid, $args ); // Adds Authorization through 'headers'.
        $this->log( "{$method} {$nsid}", 'DEBUG' );
        $res = self::xrpc_raw( $session->pds_url(), $method, $nsid, $args );
        if ( is_wp_error( $res ) ) {
            $data = (array) $res->get_error_data();
            if ( $retry && isset( $data['error'] ) && $session->on_auth_error( (int) $data['status'], $data['error'], array() ) ) {
                return $this->xrpc( $method, $nsid, $args, false );
            }
            $this->log( "{$method} {$nsid} → " . ( isset( $data['status'] ) ? $data['status'] : 0 ) . ' ' . $res->get_error_message(), 'ERROR' );
        }
        return $res;
    }

    /** @return array|WP_Error The blob reference to embed as-is. */
    public function upload_blob( $path, $mime ) {
        if ( ! is_readable( $path ) ) {
            return new WP_Error( 'bluesky_media', __( 'Image file is missing on disk.', 'wp-fediverse-posting' ), array( 'status' => 0, 'retryable' => false ) );
        }
        if ( filesize( $path ) > self::MAX_BLOB ) {
            return new WP_Error( 'bluesky_media', __( 'Bluesky accepts images up to 1 MB.', 'wp-fediverse-posting' ), array( 'status' => 0, 'retryable' => false ) );
        }
        $res = $this->xrpc( 'POST', 'com.atproto.repo.uploadBlob', array(
            'raw'          => file_get_contents( $path ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
            'content_type' => $mime,
            'timeout'      => 120,
        ) );
        if ( is_wp_error( $res ) ) {
            return $res;
        }
        if ( ! is_array( $res['body'] ) || empty( $res['body']['blob'] ) ) {
            return new WP_Error( 'bluesky_media', __( 'Bluesky did not return the uploaded image.', 'wp-fediverse-posting' ), array( 'status' => $res['code'], 'retryable' => true ) );
        }
        return $res['body']['blob'];
    }
}
