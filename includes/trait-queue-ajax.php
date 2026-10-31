<?php
/**
 * Queue AJAX trait: every action of the composer, the editor box and the Planner.
 *
 * All handlers: nonce `fediverse_queue` + the compose capability, plus
 * edit_post on the linked WP post. List HTML is always rendered on the server
 * (fediverse_render_list) and returned as `html`, so the JS never builds rows.
 *
 * Text is stored as typed (wp_unslash + UTF-8 check), never through
 * sanitize_textarea_field(), which would turn "<3" into "&lt;3". It is escaped
 * on output and sent to the server as plain text.
 *
 * A row's destinations arrive as `destinations[]` (network ids); absent means
 * the Settings default. Per-network text arrives as `network_templates[id]`.
 *
 * Used by Fediverse_Queue.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait Fediverse_Queue_Ajax_Trait {

    private function register_ajax_hooks() {
        $actions = array( 'save', 'preview', 'get', 'delete', 'retry', 'delete_remote', 'reorder', 'list', 'skip_auto' );
        foreach ( $actions as $a ) {
            add_action( 'wp_ajax_fediverse_' . $a, array( $this, 'ajax_' . $a ) );
        }
    }

    /** Nonce + capability. Returns the request's context ('editor' | 'planner'). */
    private function check_request() {
        check_ajax_referer( 'fediverse_queue', 'nonce' );
        if ( ! current_user_can( Fediverse_Posting::compose_capability() ) ) {
            wp_send_json_error( array( 'message' => __( 'You are not allowed to share posts.', 'wp-fediverse-posting' ) ), 403 );
        }
        return ( isset( $_POST['context'] ) && 'editor' === $_POST['context'] ) ? 'editor' : 'planner'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
    }

    /** The WP post a request refers to (0 allowed = standalone), permission-checked. */
    private function request_post( $post_id ) {
        $post_id = (int) $post_id;
        if ( ! $post_id ) {
            return null;
        }
        $post = get_post( $post_id );
        if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'That post does not exist or you cannot edit it.', 'wp-fediverse-posting' ) ), 403 );
        }
        return $post;
    }

    /** Row by id, permission-checked against its WP post. */
    private function request_row( $id ) {
        $row = $this->get_row( (int) $id );
        if ( ! $row ) {
            wp_send_json_error( array( 'message' => __( 'This fediverse post no longer exists.', 'wp-fediverse-posting' ) ), 404 );
        }
        if ( $row['post_id'] && get_post( $row['post_id'] ) && ! current_user_can( 'edit_post', $row['post_id'] ) ) {
            wp_send_json_error( array( 'message' => __( 'You cannot edit this post.', 'wp-fediverse-posting' ) ), 403 );
        }
        return $row;
    }

    public static function clean_text( $text, $max = 5000 ) {
        $text = wp_check_invalid_utf8( wp_unslash( (string) $text ) );
        $text = str_replace( array( "\r\n", "\r", "\0" ), array( "\n", "\n", '' ), $text );
        return mb_substr( $text, 0, $max );
    }

    private function list_response( $context, $post_id, $extra = array() ) {
        wp_send_json_success( array_merge( array(
            'html'      => fediverse_render_list( $this, $context, $post_id ),
            'next_slot' => fediverse_format_local( $this->next_slot_preview() ),
        ), $extra ) );
    }

    /**
     * Read the composer fields from $_POST into row data.
     * phpcs:disable WordPress.Security.NonceVerification.Missing -- every caller ran check_request().
     */
    private function composer_input( $post ) {
        $types = array_keys( Fediverse_Posting::content_types() );
        $type  = isset( $_POST['content_type'] ) ? sanitize_key( $_POST['content_type'] ) : Fediverse_Posting::DEFAULT_CONTENT_TYPE;
        $type  = in_array( $type, $types, true ) ? $type : Fediverse_Posting::DEFAULT_CONTENT_TYPE;

        $gallery = isset( $_POST['gallery_ids'] ) ? array_values( array_filter( array_map( 'absint', (array) $_POST['gallery_ids'] ) ) ) : array();
        $gallery = array_values( array_filter( $gallery, function ( $id ) {
            return wp_attachment_is_image( $id );
        } ) );

        $alts = array();
        if ( isset( $_POST['alt'] ) && is_array( $_POST['alt'] ) ) {
            foreach ( wp_unslash( $_POST['alt'] ) as $att_id => $alt ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned per item.
                $alt = trim( self::clean_text( $alt, 2000 ) );
                if ( '' !== $alt ) {
                    $alts[ (int) $att_id ] = $alt;
                }
            }
        }

        // Standalone: free text + optional images, no link.
        if ( ! $post ) {
            $type = $gallery ? 'gallery' : 'text';
        }

        $connected    = array_keys( $this->plugin->connected_networks() );
        $destinations = isset( $_POST['destinations'] )
            ? array_values( array_intersect( array_map( 'sanitize_key', (array) $_POST['destinations'] ), $connected ) )
            : $this->plugin->default_destinations();

        $templates = array();
        if ( isset( $_POST['network_templates'] ) && is_array( $_POST['network_templates'] ) ) {
            foreach ( $_POST['network_templates'] as $network => $text ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- clean_text() per item.
                $network = sanitize_key( $network );
                $text    = self::clean_text( $text );
                if ( $this->plugin->network( $network ) && '' !== trim( $text ) ) {
                    $templates[ $network ] = $text;
                }
            }
        }

        $visibility = isset( $_POST['visibility'] ) ? sanitize_key( $_POST['visibility'] ) : 'public';
        $language   = isset( $_POST['language'] ) ? sanitize_key( $_POST['language'] ) : '';
        $spoiler    = isset( $_POST['spoiler_text'] ) ? trim( self::clean_text( $_POST['spoiler_text'], 500 ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- clean_text().
        $label      = isset( $_POST['sensitive_label'] ) ? sanitize_key( $_POST['sensitive_label'] ) : '';

        return array(
            'post_id'           => $post ? $post->ID : 0,
            'content_type'      => $type,
            'template'          => isset( $_POST['template'] ) ? self::clean_text( $_POST['template'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- clean_text().
            'network_templates' => $templates,
            'destinations'      => $destinations,
            'gallery_ids'       => $gallery,
            'alt_overrides'     => $alts,
            'visibility'        => array_key_exists( $visibility, Fediverse_Posting::visibilities() ) ? $visibility : 'public',
            'language'          => preg_match( '/^[a-z]{2,3}$/', $language ) ? $language : '',
            'spoiler_text'      => $spoiler,
            'is_sensitive'      => ! empty( $_POST['sensitive'] ) ? 1 : 0,
            'sensitive_label'   => array_key_exists( $label, Fediverse_Network_Bluesky::labels() ) ? $label : '',
        );
    }

    /** Is the post public, so its link works? */
    private static function post_is_public( $post ) {
        return $post && 'publish' === $post->post_status && '' === $post->post_password;
    }

    /** Save (create or edit) a row from the composer; "now" also sends it. */
    public function ajax_save() {
        $context = $this->check_request();
        $id      = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $row     = $id ? $this->request_row( $id ) : null;
        $post    = $this->request_post( $row ? $row['post_id'] : ( isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0 ) );
        $when    = isset( $_POST['when'] ) ? sanitize_key( $_POST['when'] ) : 'queue';
        $data    = $this->composer_input( $post );

        if ( $row && ! in_array( $row['status'], array( 'scheduled', 'waiting', 'paused', 'failed' ), true ) ) {
            wp_send_json_error( array( 'message' => __( 'This post was already sent and can no longer be edited.', 'wp-fediverse-posting' ) ) );
        }
        if ( '' === trim( $data['template'] ) && ! $data['gallery_ids'] && 'link_image' !== $data['content_type'] ) {
            wp_send_json_error( array( 'message' => __( 'Write something first.', 'wp-fediverse-posting' ) ) );
        }
        // Targets that already went out (or were deleted there) stay whatever is ticked.
        if ( $row ) {
            foreach ( $row['targets'] as $network => $target ) {
                if ( in_array( $target['status'], array( 'published', 'removed' ), true ) && ! in_array( $network, $data['destinations'], true ) ) {
                    $data['destinations'][] = $network;
                }
            }
        }
        if ( empty( $data['destinations'] ) ) {
            wp_send_json_error( array( 'message' => __( 'Pick at least one network.', 'wp-fediverse-posting' ) ) );
        }

        switch ( $when ) {
            case 'now':
                if ( $post && ! self::post_is_public( $post ) ) {
                    wp_send_json_error( array( 'message' => __( 'Publish the post first: its link does not work yet. Or choose "When published".', 'wp-fediverse-posting' ) ) );
                }
                $data += array( 'trigger_type' => 'now', 'status' => 'scheduled', 'scheduled_at' => gmdate( 'Y-m-d H:i:s' ) );
                break;

            case 'on_publish':
                if ( ! $post || self::post_is_public( $post ) ) {
                    wp_send_json_error( array( 'message' => __( '"When published" only applies to posts that are not published yet.', 'wp-fediverse-posting' ) ) );
                }
                $data += array( 'trigger_type' => 'on_publish', 'status' => 'waiting', 'scheduled_at' => null );
                break;

            case 'at':
                $at  = isset( $_POST['at'] ) ? sanitize_text_field( wp_unslash( $_POST['at'] ) ) : '';
                $utc = fediverse_local_to_utc( $at );
                if ( ! $utc || strtotime( $utc . ' UTC' ) < time() - 60 ) {
                    wp_send_json_error( array( 'message' => __( 'Pick a date and time in the future.', 'wp-fediverse-posting' ) ) );
                }
                $data += array( 'trigger_type' => 'at', 'status' => ( $post && ! self::post_is_public( $post ) ) ? 'paused' : 'scheduled', 'scheduled_at' => $utc );
                break;

            default: // queue
                if ( ! $this->has_schedule() ) {
                    wp_send_json_error( array( 'message' => __( 'The queue has no time slots yet. Add some under Fediverse → Planner.', 'wp-fediverse-posting' ) ) );
                }
                $data += array( 'trigger_type' => 'queue', 'status' => ( $post && ! self::post_is_public( $post ) ) ? 'paused' : 'scheduled' );
                if ( ! $row || 'queue' !== $row['trigger_type'] ) {
                    $data['position'] = $this->next_position();
                }
                break;
        }

        // A fresh start for edited failed / retrying rows.
        $data['attempts']      = 0;
        $data['error_message'] = null;

        if ( $row ) {
            $this->update_row( $row['id'], $data );
            $id = $row['id'];
        } else {
            $id = $this->insert_row( $data );
        }
        $this->recalculate_schedule();

        $extra = array( 'id' => $id, 'message' => __( 'Saved.', 'wp-fediverse-posting' ) );
        if ( 'paused' === $data['status'] ) {
            $extra['message'] = __( 'Saved. It is paused until the post is published.', 'wp-fediverse-posting' );
        }
        if ( 'now' === $when ) {
            $extra = array_merge( $extra, $this->send_result_message( $this->send_row( $id ) ) );
        }
        $this->list_response( $context, 'editor' === $context && $post ? $post->ID : 0, $extra );
    }

    /** Flash message for a send_row() result: all good, partly good, or an error. */
    private function send_result_message( $result ) {
        if ( is_wp_error( $result ) ) {
            return array( 'message' => $result->get_error_message(), 'error' => true );
        }
        if ( empty( $result['failed'] ) ) {
            return array( 'message' => __( 'Posted.', 'wp-fediverse-posting' ), 'url' => $result['url'] );
        }
        $labels = array();
        foreach ( array_keys( $result['published'] ) as $id ) {
            $network  = $this->plugin->network( $id );
            $labels[] = $network ? $network->label() : $id;
        }
        $failed = array();
        foreach ( $result['failed'] as $id => $message ) {
            $network  = $this->plugin->network( $id );
            $failed[] = ( $network ? $network->label() : $id ) . ': ' . $message;
        }
        return array(
            /* translators: 1: networks that got the post, 2: "Network: error" list */
            'message' => sprintf( __( 'Posted on %1$s. %2$s', 'wp-fediverse-posting' ), implode( ', ', $labels ), implode( ' ', $failed ) ),
            'url'     => $result['url'],
            'error'   => true,
        );
    }

    /**
     * Live preview, one block per ticked network: the text as that network
     * will get it (shortened when it must be), its counter, images, the link
     * card Bluesky builds, and warnings. Common warnings come separately.
     */
    public function ajax_preview() {
        $this->check_request();
        $post   = $this->request_post( isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0 );
        $data   = $this->composer_input( $post );
        $plugin = $this->plugin;

        $common   = array();
        $networks = array();
        foreach ( $data['destinations'] as $network_id ) {
            $network = $plugin->network( $network_id );
            $limits  = $network->limits();
            $unknown = array();
            $text    = $plugin->render_template( $network->template_for_row( $data ), $post, $unknown );
            $chars   = $network->count_characters( $text );
            $over    = $chars > $limits['max_characters'];
            $media   = $plugin->resolve_media_ids( $data, $post, $limits );
            $warn    = array();

            if ( $unknown ) {
                /* translators: %s: list of placeholders */
                $common[] = sprintf( __( 'Check the spelling of: %s (unknown placeholders are sent as typed; unknown modifiers are ignored).', 'wp-fediverse-posting' ), implode( ' ', array_unique( $unknown ) ) );
            }
            if ( $over && ! $limits['hard_limit'] ) {
                $text = $network->truncate( $text, $limits['max_characters'] );
                /* translators: 1: network, 2: limit */
                $warn[] = sprintf( __( '%1$s allows %2$d characters: the text will be shortened as shown.', 'wp-fediverse-posting' ), $network->label(), $limits['max_characters'] );
            }

            $images = array();
            foreach ( $media as $att_id ) {
                $src      = wp_get_attachment_image_src( $att_id, 'medium_large' );
                $alt      = $plugin->alt_text_for( $att_id, $data, $limits['alt_max'] );
                $images[] = array(
                    'id'          => $att_id,
                    'url'         => $src ? $src[0] : '',
                    'alt'         => $alt,
                    'alt_library' => (string) get_post_meta( $att_id, '_wp_attachment_image_alt', true ),
                );
            }

            $card = null;
            if ( $post && ! $network->supports( 'server_link_card' ) && in_array( $data['content_type'], array( 'link', 'link_image', 'gallery' ), true ) && empty( $media ) ) {
                $card = array(
                    'title'       => wp_specialchars_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES ),
                    'description' => Fediverse_Posting::excerpt_for( $post ),
                    'host'        => wp_parse_url( home_url(), PHP_URL_HOST ),
                    'thumb'       => '',
                );
            } elseif ( $media && $network->supports( 'server_link_card' ) && preg_match( '#https?://#', $text ) ) {
                /* translators: %s: network */
                $warn[] = sprintf( __( 'With images attached, %s shows the images instead of a link preview card.', 'wp-fediverse-posting' ), $network->label() );
            }

            $networks[ $network_id ] = array(
                'label'    => $network->label(),
                'text'     => $text,
                'chars'    => $over && ! $limits['hard_limit'] ? $network->count_characters( $text ) : $chars,
                'max'      => (int) $limits['max_characters'],
                'over'     => $over,
                'hard'     => (bool) $limits['hard_limit'],
                'images'   => $images,
                'card'     => $card,
                'warnings' => $warn,
            );
        }

        // Alt-text list in the composer: the images of the first network (the set is the same, the cap may differ).
        $first  = $networks ? reset( $networks ) : null;
        $media  = $plugin->resolve_media_ids( $data, $post, $plugin->default_limits() );
        $images = array();
        foreach ( $media as $att_id ) {
            $src      = wp_get_attachment_image_src( $att_id, 'medium_large' );
            $images[] = array(
                'id'          => $att_id,
                'url'         => $src ? $src[0] : '',
                'alt'         => $plugin->alt_text_for( $att_id, $data, 1500 ),
                'alt_library' => (string) get_post_meta( $att_id, '_wp_attachment_image_alt', true ),
            );
        }

        if ( $post && ! self::post_is_public( $post ) && false !== strpos( $data['template'], '[post-url]' ) ) {
            $common[] = __( 'The post is not published yet, so its link does not work. "Now" is off until it is.', 'wp-fediverse-posting' );
        }
        if ( 'link_image' === $data['content_type'] && empty( $media ) && $post ) {
            $common[] = __( 'This post has no featured image: it will go out as text + link.', 'wp-fediverse-posting' );
        }
        foreach ( $images as $img ) {
            if ( '' === $img['alt'] ) {
                $common[] = __( 'An image has no alt text. Many people rely on it (and some boost only described images).', 'wp-fediverse-posting' );
                break;
            }
        }
        if ( empty( $networks ) ) {
            $common[] = __( 'Pick at least one network.', 'wp-fediverse-posting' );
        }

        wp_send_json_success( array(
            'networks' => $networks,
            'images'   => $images,
            'warnings' => array_values( array_unique( $common ) ),
        ) );
    }

    /** Row data for the composer (edit). */
    public function ajax_get() {
        $this->check_request();
        $row = $this->request_row( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );
        $row['at_local'] = $row['scheduled_at'] ? fediverse_utc_to_local_input( $row['scheduled_at'] ) : '';
        $row['post_public'] = self::post_is_public( $row['post_id'] ? get_post( $row['post_id'] ) : null );
        wp_send_json_success( array( 'row' => $row ) );
    }

    /** Remove a row that has not gone out (or forget a history row). */
    public function ajax_delete() {
        $context = $this->check_request();
        $row     = $this->request_row( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );
        if ( 'processing' === $row['status'] ) {
            wp_send_json_error( array( 'message' => __( 'It is being sent right now.', 'wp-fediverse-posting' ) ) );
        }
        $this->delete_row( $row['id'] );
        $this->recalculate_schedule();
        $this->list_response( $context, 'editor' === $context ? $row['post_id'] : 0 );
    }

    /** Failed or paused row → send now. With `target`, only that network is reset. */
    public function ajax_retry() {
        $context = $this->check_request();
        $row     = $this->request_row( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );
        $target  = isset( $_POST['target'] ) ? absint( $_POST['target'] ) : 0;
        if ( ! in_array( $row['status'], array( 'failed', 'paused' ), true ) ) {
            wp_send_json_error( array( 'message' => __( 'Only failed or paused posts can be retried.', 'wp-fediverse-posting' ) ) );
        }
        foreach ( $row['targets'] as $t ) {
            if ( 'failed' === $t['status'] && ( ! $target || $target === $t['id'] ) ) {
                $this->update_target( $t['id'], array( 'status' => 'pending', 'attempts' => 0, 'retry_at' => null, 'error_message' => null ) );
            }
        }
        $this->update_row( $row['id'], array( 'status' => 'scheduled', 'scheduled_at' => gmdate( 'Y-m-d H:i:s' ), 'attempts' => 0, 'error_message' => null ) );
        $extra = $this->send_result_message( $this->send_row( $row['id'] ) );
        $this->list_response( $context, 'editor' === $context ? $row['post_id'] : 0, $extra );
    }

    /** Published target → delete the post on its network, keep it as "removed". */
    public function ajax_delete_remote() {
        $context = $this->check_request();
        $row     = $this->request_row( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );
        $id      = isset( $_POST['target'] ) ? absint( $_POST['target'] ) : 0;
        $target  = null;
        foreach ( $row['targets'] as $t ) {
            if ( 'published' === $t['status'] && '' !== $t['remote_id'] && ( $id === $t['id'] || ( ! $id && ! $target ) ) ) {
                $target = $t;
            }
        }
        if ( ! $target ) {
            wp_send_json_error( array( 'message' => __( 'This post is not on that network.', 'wp-fediverse-posting' ) ) );
        }
        $network = $this->plugin->network( $target['network'] );
        if ( ! $network || ! $network->is_connected() ) {
            wp_send_json_error( array( 'message' => __( 'That network is not connected.', 'wp-fediverse-posting' ) ) );
        }
        $res = $network->delete( $target['remote_id'] );
        if ( is_wp_error( $res ) ) {
            wp_send_json_error( array( 'message' => $res->get_error_message() ) );
        }
        $this->update_target( $target['id'], array( 'status' => 'removed' ) );
        $this->settle_row( $row );
        /* translators: %s: network */
        $this->list_response( $context, 'editor' === $context ? $row['post_id'] : 0, array( 'message' => sprintf( __( 'Deleted on %s.', 'wp-fediverse-posting' ), $network->label() ) ) );
    }

    /** Planner drag-and-drop: new order of queue rows. */
    public function ajax_reorder() {
        $context = $this->check_request();
        $ids     = isset( $_POST['ids'] ) ? array_map( 'absint', (array) $_POST['ids'] ) : array();
        $this->reorder( array_filter( $ids ) );
        $this->list_response( $context, 0 );
    }

    /** Fresh list HTML (after the block editor saved, for instance). */
    public function ajax_list() {
        $context = $this->check_request();
        $post    = $this->request_post( isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0 );
        $this->list_response( $context, $post ? $post->ID : 0, array( 'post_public' => self::post_is_public( $post ) ) );
    }

    /** Editor: opt this post out of (or back into) "post when published" defaults. */
    public function ajax_skip_auto() {
        $context = $this->check_request();
        $post    = $this->request_post( isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0 );
        if ( ! $post ) {
            wp_send_json_error();
        }
        if ( ! empty( $_POST['skip'] ) ) {
            update_post_meta( $post->ID, self::SKIP_AUTO_META, 1 );
        } else {
            delete_post_meta( $post->ID, self::SKIP_AUTO_META );
        }
        ob_start();
        fediverse_render_auto_note( $this, $post );
        $this->list_response( $context, $post->ID, array( 'note_html' => ob_get_clean() ) );
    }
    // phpcs:enable
}
