<?php
/**
 * Mastodon API trait: the only place that talks HTTP to the server.
 *
 * Works with any server implementing the Mastodon client API (Mastodon,
 * GoToSocial, Akkoma/Pleroma). Statuses are sent as JSON so `media_ids`
 * arrives as a real array on every implementation.
 *
 * Errors come back as WP_Error with data:
 *   status      HTTP code (0 = network failure / timeout)
 *   retryable   true for 0, 429 and 5xx: the queue retries those
 *   retry_at    unix time the server asks us to wait until (429), if given
 *
 * Used by Fediverse_Network_Mastodon. Uses: get_instance(), get_token(), is_connected(), log().
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait Fediverse_Mastodon_API_Trait {

    /**
     * Low-level request against any server.
     *
     * @param string $instance Host, e.g. "ciberlandia.pt".
     * @param string $method   GET | POST | DELETE.
     * @param string $path     "/api/v1/…".
     * @param array  $args {
     *     @type array  $body      Form fields (OAuth endpoints).
     *     @type array  $json      JSON body (statuses).
     *     @type string $raw       Pre-built body (multipart), with $content_type.
     *     @type string $content_type
     *     @type string $token     Bearer token.
     *     @type array  $headers   Extra headers.
     *     @type int    $timeout   Seconds (default 30).
     * }
     * @return array|WP_Error { code, body (decoded JSON or raw string), headers }
     */
    public function api_request_raw( $instance, $method, $path, $args = array() ) {
        $url     = 'https://' . $instance . $path;
        $headers = isset( $args['headers'] ) ? (array) $args['headers'] : array();
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
        } elseif ( isset( $args['body'] ) ) {
            $request['body'] = $args['body'];
        }

        $this->log( "{$method} {$url}", 'DEBUG' );
        $response = wp_remote_request( $url, $request );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'fediverse_http', $response->get_error_message(), array( 'status' => 0, 'retryable' => true ) );
        }

        $code    = (int) wp_remote_retrieve_response_code( $response );
        $raw     = wp_remote_retrieve_body( $response );
        $decoded = json_decode( $raw, true );
        $body    = ( null === $decoded ) ? $raw : $decoded;

        if ( $code < 200 || $code >= 300 ) {
            $message = is_array( $body ) && ! empty( $body['error'] ) ? (string) $body['error'] : sprintf( 'HTTP %d', $code );
            if ( is_array( $body ) && ! empty( $body['error_description'] ) ) {
                $message .= ': ' . $body['error_description'];
            }
            $data = array( 'status' => $code, 'retryable' => ( 429 === $code || $code >= 500 ) );
            if ( 429 === $code ) {
                $reset = wp_remote_retrieve_header( $response, 'x-ratelimit-reset' );
                if ( $reset && false !== strtotime( $reset ) ) {
                    $data['retry_at'] = strtotime( $reset );
                }
            }
            $this->log( "{$method} {$path} → {$code} {$message}", 'ERROR' );
            return new WP_Error( 'fediverse_api', $message, $data );
        }

        return array(
            'code'    => $code,
            'body'    => $body,
            'headers' => wp_remote_retrieve_headers( $response ),
        );
    }

    /** Request against the connected server with the stored token. */
    public function api_request( $method, $path, $args = array() ) {
        if ( ! $this->is_connected() ) {
            return new WP_Error( 'fediverse_not_connected', __( 'Not connected to a fediverse account. Connect one in Fediverse → Settings.', 'wp-fediverse-posting' ), array( 'status' => 0, 'retryable' => false ) );
        }
        $args['token'] = $this->get_token();
        return $this->api_request_raw( $this->get_instance(), $method, $path, $args );
    }

    /**
     * @return array|WP_Error Trimmed account: id, acct (user@host), username, display_name, avatar, url.
     */
    public function verify_credentials( $instance, $token ) {
        $res = $this->api_request_raw( $instance, 'GET', '/api/v1/accounts/verify_credentials', array( 'token' => $token ) );
        if ( is_wp_error( $res ) ) {
            return $res;
        }
        $a = is_array( $res['body'] ) ? $res['body'] : array();
        if ( empty( $a['id'] ) ) {
            return new WP_Error( 'fediverse_verify', __( 'The server did not return an account for this token.', 'wp-fediverse-posting' ) );
        }
        $acct = isset( $a['acct'] ) ? (string) $a['acct'] : (string) $a['username'];
        if ( false === strpos( $acct, '@' ) ) {
            $acct .= '@' . $instance;
        }
        return array(
            'id'           => (string) $a['id'],
            'acct'         => $acct,
            'username'     => isset( $a['username'] ) ? (string) $a['username'] : '',
            'display_name' => isset( $a['display_name'] ) && '' !== $a['display_name'] ? (string) $a['display_name'] : (string) $a['username'],
            'avatar'       => isset( $a['avatar_static'] ) ? esc_url_raw( $a['avatar_static'] ) : ( isset( $a['avatar'] ) ? esc_url_raw( $a['avatar'] ) : '' ),
            'url'          => isset( $a['url'] ) ? esc_url_raw( $a['url'] ) : '',
        );
    }

    /**
     * The server's limits, cached for a day. Falls back to Mastodon's defaults
     * so the UI still works when the server is unreachable.
     *
     * @return array { max_characters, url_length, max_media, image_size_limit, image_types[] }
     */
    public function instance_limits() {
        $defaults = array(
            'max_characters'   => 500,
            'url_length'       => 23,
            'max_media'        => 4,
            'image_size_limit' => 16 * MB_IN_BYTES,
            'image_types'      => array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ),
        );
        $instance = $this->get_instance();
        if ( '' === $instance ) {
            return $defaults;
        }

        $key    = 'fediverse_instance_limits_' . md5( $instance );
        $cached = get_transient( $key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $res = $this->api_request_raw( $instance, 'GET', '/api/v2/instance', array( 'timeout' => 10 ) );
        if ( is_wp_error( $res ) ) {
            $res = $this->api_request_raw( $instance, 'GET', '/api/v1/instance', array( 'timeout' => 10 ) );
        }
        if ( is_wp_error( $res ) || ! is_array( $res['body'] ) ) {
            set_transient( $key, $defaults, HOUR_IN_SECONDS );
            return $defaults;
        }

        $conf     = isset( $res['body']['configuration'] ) ? $res['body']['configuration'] : array();
        $statuses = isset( $conf['statuses'] ) ? $conf['statuses'] : array();
        $media    = isset( $conf['media_attachments'] ) ? $conf['media_attachments'] : array();

        $limits = array(
            'max_characters'   => ! empty( $statuses['max_characters'] ) ? (int) $statuses['max_characters'] : ( ! empty( $res['body']['max_toot_chars'] ) ? (int) $res['body']['max_toot_chars'] : $defaults['max_characters'] ),
            'url_length'       => isset( $statuses['characters_reserved_per_url'] ) ? (int) $statuses['characters_reserved_per_url'] : $defaults['url_length'],
            'max_media'        => ! empty( $statuses['max_media_attachments'] ) ? (int) $statuses['max_media_attachments'] : $defaults['max_media'],
            'image_size_limit' => ! empty( $media['image_size_limit'] ) ? (int) $media['image_size_limit'] : $defaults['image_size_limit'],
            'image_types'      => ! empty( $media['supported_mime_types'] ) ? array_values( array_filter( (array) $media['supported_mime_types'], function ( $m ) {
                return 0 === strpos( $m, 'image/' );
            } ) ) : $defaults['image_types'],
        );
        if ( empty( $limits['image_types'] ) ) {
            $limits['image_types'] = $defaults['image_types'];
        }

        set_transient( $key, $limits, DAY_IN_SECONDS );
        return $limits;
    }

    /**
     * Upload one image. Waits for servers that process media asynchronously
     * (202 + url null) so the id is usable in a status right away.
     *
     * @return string|WP_Error Media id.
     */
    public function upload_media( $file_path, $mime, $description = '' ) {
        if ( ! is_readable( $file_path ) ) {
            return new WP_Error( 'fediverse_media', __( 'Image file is missing on disk.', 'wp-fediverse-posting' ), array( 'status' => 0, 'retryable' => false ) );
        }

        $boundary = 'fedi' . wp_generate_password( 24, false );
        $body     = '';
        if ( '' !== $description ) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"description\"\r\n\r\n{$description}\r\n";
        }
        $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"" . basename( $file_path ) . "\"\r\n";
        $body .= "Content-Type: {$mime}\r\n\r\n";
        $body .= file_get_contents( $file_path ) . "\r\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
        $body .= "--{$boundary}--\r\n";

        $res = $this->api_request( 'POST', '/api/v2/media', array(
            'raw'          => $body,
            'content_type' => 'multipart/form-data; boundary=' . $boundary,
            'timeout'      => 120,
        ) );
        if ( is_wp_error( $res ) ) {
            return $res;
        }

        $media = is_array( $res['body'] ) ? $res['body'] : array();
        if ( empty( $media['id'] ) ) {
            return new WP_Error( 'fediverse_media', __( 'The server did not return a media id.', 'wp-fediverse-posting' ), array( 'status' => $res['code'], 'retryable' => true ) );
        }

        // 202: still processing. Poll until it has a URL (max ~60 s).
        $tries = 0;
        while ( empty( $media['url'] ) && $tries < 30 ) {
            sleep( 2 );
            $tries++;
            $check = $this->api_request( 'GET', '/api/v1/media/' . rawurlencode( $media['id'] ), array( 'timeout' => 15 ) );
            if ( ! is_wp_error( $check ) && is_array( $check['body'] ) ) {
                $media = array_merge( $media, $check['body'] );
            }
        }
        if ( empty( $media['url'] ) ) {
            return new WP_Error( 'fediverse_media', __( 'The server is still processing the image. Try again in a minute.', 'wp-fediverse-posting' ), array( 'status' => 0, 'retryable' => true ) );
        }

        return (string) $media['id'];
    }

    /**
     * Create a status.
     *
     * @param array  $params          status, media_ids[], visibility, language, spoiler_text, sensitive.
     * @param string $idempotency_key Same key within an hour → the server returns the first status instead of posting twice.
     * @return array|WP_Error { id, url }
     */
    public function post_status( $params, $idempotency_key ) {
        $res = $this->api_request( 'POST', '/api/v1/statuses', array(
            'json'    => $params,
            'headers' => array( 'Idempotency-Key' => $idempotency_key ),
            'timeout' => 60,
        ) );
        if ( is_wp_error( $res ) ) {
            return $res;
        }
        $body = is_array( $res['body'] ) ? $res['body'] : array();
        if ( empty( $body['id'] ) ) {
            return new WP_Error( 'fediverse_status', __( 'The server did not return the new post.', 'wp-fediverse-posting' ), array( 'status' => $res['code'], 'retryable' => false ) );
        }
        return array(
            'id'  => (string) $body['id'],
            'url' => isset( $body['url'] ) ? esc_url_raw( $body['url'] ) : ( isset( $body['uri'] ) ? esc_url_raw( $body['uri'] ) : '' ),
        );
    }

    /** @return true|WP_Error */
    public function delete_status( $id ) {
        $res = $this->api_request( 'DELETE', '/api/v1/statuses/' . rawurlencode( $id ) );
        if ( is_wp_error( $res ) ) {
            $data = $res->get_error_data();
            // Already gone on the server: treat as deleted.
            if ( is_array( $data ) && 404 === $data['status'] ) {
                return true;
            }
            return $res;
        }
        return true;
    }
}
