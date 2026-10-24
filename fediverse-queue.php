<?php
/**
 * Fediverse_Queue: every fediverse post is one row in {prefix}fediverse_queue.
 *
 * Rows and their life:
 *   trigger_type  now | on_publish | queue | at
 *   status        waiting   (on_publish, until the WP post goes public)
 *                 scheduled (has scheduled_at; the cron sends it when due)
 *                 processing → published | failed
 *                 paused    (its WP post left `publish` or went to the trash)
 *                 removed   (published, then deleted on the server from here)
 *
 * Guarantees:
 *   - A row is claimed with one UPDATE … WHERE status='scheduled' before it is
 *     sent, so two cron runs (or cron + "Post now") can never both send it.
 *   - Every send carries Idempotency-Key "wpfp-<site>-<row id>"; a retry within
 *     the server's hour returns the first post instead of a second one. That
 *     is why a row stuck in `processing` (PHP died) can safely go back to
 *     `scheduled`.
 *   - Templates are filled in at send time (Fediverse_Content_Trait).
 *
 * Queue rows (trigger queue) are ordered by `position` and get the next free
 * weekly slot; fixed-time rows (trigger at) keep their moment and queue rows
 * flow around them. Slot maths ported from instagram-posting-api.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Fediverse_Queue {

    use Fediverse_Queue_Ajax_Trait;
    use Fediverse_Queue_Editor_Trait;

    const TABLE         = 'fediverse_queue';
    const DB_VERSION    = '1.0';
    const DB_OPTION     = 'fediverse_queue_db_version';
    const CRON_HOOK     = 'fediverse_queue_process';
    const CRON_INTERVAL = 'fediverse_5_minutes';
    const CLEANUP_HOOK  = 'fediverse_queue_cleanup';
    const SEND_HOOK     = 'fediverse_queue_send_row';
    const HISTORY_DAYS  = 30;
    const MAX_ATTEMPTS  = 3;
    const STUCK_MINUTES = 15;
    const SKIP_AUTO_META = '_fediverse_skip_auto';
    const POSTED_META    = '_fediverse_posted_at';

    const DAYS = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );

    /** @var Fediverse_Posting */
    public $plugin;

    public function __construct( $plugin ) {
        $this->plugin = $plugin;

        add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade' ) );
        add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_interval' ) );
        add_action( 'init', array( __CLASS__, 'ensure_cron' ) );
        add_action( self::CRON_HOOK, array( $this, 'process_queue' ) );
        add_action( self::CLEANUP_HOOK, array( $this, 'cleanup' ) );
        add_action( self::SEND_HOOK, array( $this, 'send_row' ) );

        add_action( 'transition_post_status', array( $this, 'on_transition_post_status' ), 20, 3 );
        add_action( 'before_delete_post', array( $this, 'on_delete_post' ) );

        $this->register_editor_hooks();
        $this->register_ajax_hooks();
    }


    // =====================================================================
    //  TABLE
    // =====================================================================

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /** `trigger` and `sensitive` are reserved words in MySQL, hence trigger_type and is_sensitive. */
    public static function create_table() {
        global $wpdb;
        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            content_type varchar(20) NOT NULL DEFAULT 'link_image',
            template text NOT NULL,
            gallery_ids text NULL,
            alt_overrides text NULL,
            visibility varchar(20) NOT NULL DEFAULT 'public',
            language varchar(10) NOT NULL DEFAULT '',
            spoiler_text varchar(500) NOT NULL DEFAULT '',
            is_sensitive tinyint(1) NOT NULL DEFAULT 0,
            trigger_type varchar(20) NOT NULL DEFAULT 'queue',
            position int(11) NOT NULL DEFAULT 0,
            scheduled_at datetime NULL DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'scheduled',
            attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
            error_message text NULL,
            remote_id varchar(100) NOT NULL DEFAULT '',
            remote_url varchar(500) NOT NULL DEFAULT '',
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            published_at datetime NULL DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY status_scheduled (status,scheduled_at),
            KEY post_id (post_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        update_option( self::DB_OPTION, self::DB_VERSION, false );
    }

    /** Per site (multisite-safe) and after plugin updates. */
    public static function maybe_upgrade() {
        if ( get_option( self::DB_OPTION ) !== self::DB_VERSION ) {
            self::create_table();
        }
    }

    private static function now_utc() {
        return gmdate( 'Y-m-d H:i:s' );
    }

    /** Decode a DB row into typed values. */
    private static function hydrate( $row ) {
        if ( ! $row ) {
            return null;
        }
        $row                  = (array) $row;
        $row['id']            = (int) $row['id'];
        $row['post_id']       = (int) $row['post_id'];
        $row['is_sensitive']  = (int) $row['is_sensitive'];
        $row['position']      = (int) $row['position'];
        $row['attempts']      = (int) $row['attempts'];
        $gallery              = json_decode( (string) $row['gallery_ids'], true );
        $row['gallery_ids']   = is_array( $gallery ) ? array_map( 'intval', $gallery ) : array();
        $alts                 = json_decode( (string) $row['alt_overrides'], true );
        $row['alt_overrides'] = is_array( $alts ) ? $alts : array();
        return $row;
    }

    public function get_row( $id ) {
        global $wpdb;
        $table = self::table_name();
        return self::hydrate( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * @param array $args post_id (int|null), statuses (string[]|null).
     * @return array[] Ordered: soonest scheduled first, then waiting/paused/failed, then newest history.
     */
    public function get_rows( $args = array() ) {
        global $wpdb;
        $table = self::table_name();
        $where = array( '1=1' );
        $vals  = array();

        if ( isset( $args['post_id'] ) && null !== $args['post_id'] ) {
            $where[] = 'post_id = %d';
            $vals[]  = (int) $args['post_id'];
        }
        if ( ! empty( $args['statuses'] ) ) {
            $where[] = 'status IN (' . implode( ',', array_fill( 0, count( $args['statuses'] ), '%s' ) ) . ')';
            $vals    = array_merge( $vals, array_values( $args['statuses'] ) );
        }

        $sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where )
             . " ORDER BY FIELD(status,'processing','scheduled','waiting','failed','paused','published','removed'),"
             . ' scheduled_at IS NULL, scheduled_at ASC, position ASC, published_at DESC, id DESC';
        $sql = $vals ? $wpdb->prepare( $sql, $vals ) : $sql; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return array_map( array( __CLASS__, 'hydrate' ), (array) $wpdb->get_results( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    public function count_failed() {
        global $wpdb;
        $table = self::table_name();
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'failed'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /** @return int New row id. */
    public function insert_row( $data ) {
        global $wpdb;
        $now  = self::now_utc();
        $data = array_merge( array(
            'post_id'       => 0,
            'content_type'  => Fediverse_Posting::DEFAULT_CONTENT_TYPE,
            'template'      => '',
            'gallery_ids'   => array(),
            'alt_overrides' => array(),
            'visibility'    => 'public',
            'language'      => '',
            'spoiler_text'  => '',
            'is_sensitive'  => 0,
            'trigger_type'  => 'queue',
            'position'      => 0,
            'scheduled_at'  => null,
            'status'        => 'scheduled',
            'created_by'    => get_current_user_id(),
            'created_at'    => $now,
            'updated_at'    => $now,
        ), $data );
        $data['gallery_ids']   = wp_json_encode( array_values( array_map( 'intval', (array) $data['gallery_ids'] ) ) );
        $data['alt_overrides'] = wp_json_encode( (object) $data['alt_overrides'] );
        $wpdb->insert( self::table_name(), $data );
        return (int) $wpdb->insert_id;
    }

    public function update_row( $id, $data ) {
        global $wpdb;
        if ( array_key_exists( 'gallery_ids', $data ) ) {
            $data['gallery_ids'] = wp_json_encode( array_values( array_map( 'intval', (array) $data['gallery_ids'] ) ) );
        }
        if ( array_key_exists( 'alt_overrides', $data ) ) {
            $data['alt_overrides'] = wp_json_encode( (object) $data['alt_overrides'] );
        }
        $data['updated_at'] = self::now_utc();
        return false !== $wpdb->update( self::table_name(), $data, array( 'id' => (int) $id ) );
    }

    public function delete_row( $id ) {
        global $wpdb;
        $wpdb->delete( self::table_name(), array( 'id' => (int) $id ), array( '%d' ) );
    }


    // =====================================================================
    //  SCHEDULE (weekly slots, ported from Instagram_Queue)
    // =====================================================================

    public function is_enabled() {
        return (bool) $this->plugin->get_setting( 'queue_enabled' );
    }

    public function get_schedule() {
        $schedule = (array) $this->plugin->get_setting( 'queue_schedule' );
        $out      = array();
        foreach ( self::DAYS as $day ) {
            $out[ $day ] = isset( $schedule[ $day ] ) && is_array( $schedule[ $day ] ) ? $schedule[ $day ] : array();
        }
        return $out;
    }

    public function has_schedule() {
        return ! empty( $this->get_weekly_slots() );
    }

    /** Flat sorted list of (ISO day 1–7, 'HH:MM'). */
    private function get_weekly_slots() {
        $slots = array();
        $i     = 1;
        foreach ( $this->get_schedule() as $times ) {
            sort( $times );
            foreach ( $times as $t ) {
                $slots[] = array( 'day' => $i, 'time' => $t );
            }
            $i++;
        }
        return $slots;
    }

    private function local_to_utc( $date, $time ) {
        $local = new DateTime( $date . ' ' . $time . ':00', wp_timezone() );
        $local->setTimezone( new DateTimeZone( 'UTC' ) );
        return $local;
    }

    /**
     * Future slot moments (UTC 'Y-m-d H:i:s'), in order, skipping $taken.
     *
     * @param int   $count How many to return.
     * @param array $taken Lookup of moments already used.
     */
    private function free_slots( $count, $taken = array() ) {
        $slots = $this->get_weekly_slots();
        $out   = array();
        if ( empty( $slots ) || $count < 1 ) {
            return $out;
        }
        $now  = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
        $date = new DateTime( 'now', wp_timezone() );
        $date->setTime( 0, 0, 0 );

        for ( $d = 0; $d < 400 && count( $out ) < $count; $d++ ) {
            $iso = (int) $date->format( 'N' );
            foreach ( $slots as $slot ) {
                if ( $slot['day'] !== $iso ) {
                    continue;
                }
                $utc = $this->local_to_utc( $date->format( 'Y-m-d' ), $slot['time'] );
                $key = $utc->format( 'Y-m-d H:i:s' );
                if ( $utc <= $now || isset( $taken[ $key ] ) ) {
                    continue;
                }
                $out[]         = $key;
                $taken[ $key ] = true;
                if ( count( $out ) >= $count ) {
                    break;
                }
            }
            $date->modify( '+1 day' );
        }
        return $out;
    }

    /** Moments held by fixed-time rows (queue rows must not land on them). */
    private function fixed_moments() {
        global $wpdb;
        $table = self::table_name();
        $rows  = $wpdb->get_col( "SELECT scheduled_at FROM {$table} WHERE trigger_type = 'at' AND status IN ('scheduled','processing') AND scheduled_at IS NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return array_fill_keys( (array) $rows, true );
    }

    /** Next slot a new queue row would get (UTC string), or '' with no schedule. */
    public function next_slot_preview() {
        global $wpdb;
        $table = self::table_name();
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE trigger_type = 'queue' AND status = 'scheduled' AND attempts = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $slots = $this->free_slots( $count + 1, $this->fixed_moments() );
        return $slots ? end( $slots ) : '';
    }

    /**
     * Give every queue row (in `position` order) the next free slot. Rows in a
     * retry back-off (attempts > 0) keep their time.
     */
    public function recalculate_schedule() {
        global $wpdb;
        $table = self::table_name();
        $ids   = $wpdb->get_col( "SELECT id FROM {$table} WHERE trigger_type = 'queue' AND status = 'scheduled' AND attempts = 0 ORDER BY position ASC, id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( empty( $ids ) ) {
            return;
        }
        $slots = $this->free_slots( count( $ids ), $this->fixed_moments() );
        foreach ( $ids as $i => $id ) {
            $wpdb->update( $table, array( 'scheduled_at' => isset( $slots[ $i ] ) ? $slots[ $i ] : null ), array( 'id' => (int) $id ) );
        }
    }

    /** Position after the last queue row. */
    public function next_position() {
        global $wpdb;
        $table = self::table_name();
        return 1 + (int) $wpdb->get_var( "SELECT MAX(position) FROM {$table} WHERE trigger_type = 'queue'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /** @param int[] $ids Queue row ids in their new order. */
    public function reorder( $ids ) {
        foreach ( array_values( $ids ) as $i => $id ) {
            $this->update_row( (int) $id, array( 'position' => $i + 1 ) );
        }
        $this->recalculate_schedule();
    }


    // =====================================================================
    //  SENDING
    // =====================================================================

    /** wp-cron, every 5 minutes: free stuck rows, then send one due row. */
    public function process_queue() {
        global $wpdb;
        $table = self::table_name();

        // PHP died mid-send: put it back. Same row id → same Idempotency-Key → no double post.
        $stuck = gmdate( 'Y-m-d H:i:s', time() - self::STUCK_MINUTES * MINUTE_IN_SECONDS );
        $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'scheduled' WHERE status = 'processing' AND updated_at < %s", $stuck ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ( ! $this->plugin->is_connected() ) {
            return;
        }

        $paused_triggers = $this->is_enabled() ? '' : " AND trigger_type NOT IN ('queue','at')";
        $id              = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE status = 'scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= %s{$paused_triggers} ORDER BY scheduled_at ASC, id ASC LIMIT 1", self::now_utc() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ( $id ) {
            $this->send_row( (int) $id );
        }
    }

    /**
     * Claim and send one row. Safe to call from cron, a single event and AJAX.
     *
     * @return array|WP_Error { id, url } on success.
     */
    public function send_row( $id ) {
        global $wpdb;
        $table = self::table_name();

        // Leave the row as it is: it goes out once an account is connected.
        if ( ! $this->plugin->is_connected() ) {
            return new WP_Error( 'fediverse_not_connected', __( 'Not connected to a fediverse account. Connect one in Fediverse → Settings.', 'wp-fediverse-posting' ) );
        }

        $claimed =$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'processing', updated_at = %s WHERE id = %d AND status = 'scheduled'", self::now_utc(), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( 1 !== (int) $claimed ) {
            return new WP_Error( 'fediverse_claimed', __( 'This post is already being sent or is not scheduled.', 'wp-fediverse-posting' ) );
        }

        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
        ignore_user_abort( true );

        $row    = $this->get_row( $id );
        $plugin = $this->plugin;
        $post   = null;

        if ( $row['post_id'] ) {
            $post = get_post( $row['post_id'] );
            if ( ! $post || 'publish' !== $post->post_status || '' !== $post->post_password ) {
                return $this->fail( $row, new WP_Error( 'fediverse_not_public', __( 'The WordPress post is not public (draft, private, password-protected or deleted).', 'wp-fediverse-posting' ), array( 'retryable' => false ) ) );
            }
        }

        $text      = $plugin->render_template( $row['template'], $post );
        $media_ids = $plugin->resolve_media_ids( $row, $post );
        $limits    = $plugin->instance_limits();

        if ( '' === $text && empty( $media_ids ) ) {
            return $this->fail( $row, new WP_Error( 'fediverse_empty', __( 'Nothing to send: the text is empty and there are no images.', 'wp-fediverse-posting' ), array( 'retryable' => false ) ) );
        }
        $chars = $plugin->count_characters( $text );
        if ( $chars > $limits['max_characters'] ) {
            /* translators: 1: characters used, 2: server limit */
            return $this->fail( $row, new WP_Error( 'fediverse_too_long', sprintf( __( 'The text is %1$d characters; the server allows %2$d.', 'wp-fediverse-posting' ), $chars, $limits['max_characters'] ), array( 'retryable' => false ) ) );
        }

        // Upload every image first: all or nothing.
        $uploaded = array();
        foreach ( $media_ids as $att_id ) {
            $file = $plugin->prepare_media_file( $att_id );
            if ( is_wp_error( $file ) ) {
                return $this->fail( $row, $file );
            }
            $media_id = $plugin->upload_media( $file['path'], $file['mime'], $plugin->alt_text_for( $att_id, $row ) );
            if ( $file['temp'] ) {
                wp_delete_file( $file['path'] );
            }
            if ( is_wp_error( $media_id ) ) {
                return $this->fail( $row, $media_id );
            }
            $uploaded[] = $media_id;
        }

        $params = array(
            'status'     => $text,
            'visibility' => $row['visibility'],
        );
        $language = $plugin->resolve_language( $row['language'], $post );
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
        $params = apply_filters( 'fediverse_posting_status_params', $params, $row, $post );

        $result = $plugin->post_status( $params, self::idempotency_key( $row['id'] ) );
        if ( is_wp_error( $result ) ) {
            return $this->fail( $row, $result );
        }

        $this->update_row( $row['id'], array(
            'status'        => 'published',
            'remote_id'     => $result['id'],
            'remote_url'    => $result['url'],
            'published_at'  => self::now_utc(),
            'error_message' => null,
            'attempts'      => $row['attempts'] + 1,
        ) );
        if ( $row['post_id'] ) {
            update_post_meta( $row['post_id'], self::POSTED_META, time() );
        }
        $plugin->log( "Row {$row['id']} published: {$result['url']}", 'INFO' );
        do_action( 'fediverse_posting_published', $result, $row, $post );

        return $result;
    }

    public static function idempotency_key( $row_id ) {
        return 'wpfp-' . substr( md5( home_url() ), 0, 12 ) . '-' . (int) $row_id;
    }

    /**
     * Retry network, 429 and 5xx errors (5, 15, 30 min); anything else, or the
     * last attempt, fails the row and emails the admin.
     */
    private function fail( $row, WP_Error $error ) {
        $data      = (array) $error->get_error_data();
        $attempts  = $row['attempts'] + 1;
        $retryable = ! empty( $data['retryable'] ) && $attempts < self::MAX_ATTEMPTS;
        $message   = $error->get_error_message();

        if ( $retryable ) {
            $backoff = array( 1 => 5, 2 => 15 );
            $wait    = isset( $backoff[ $attempts ] ) ? $backoff[ $attempts ] : 30;
            $when    = time() + $wait * MINUTE_IN_SECONDS;
            if ( ! empty( $data['retry_at'] ) && $data['retry_at'] > $when ) {
                $when = (int) $data['retry_at'];
            }
            $this->update_row( $row['id'], array(
                'status'        => 'scheduled',
                'scheduled_at'  => gmdate( 'Y-m-d H:i:s', $when ),
                'attempts'      => $attempts,
                /* translators: %s: error message */
                'error_message' => sprintf( __( 'Retrying: %s', 'wp-fediverse-posting' ), $message ),
            ) );
            $this->plugin->log( "Row {$row['id']} attempt {$attempts} failed, retrying: {$message}", 'ERROR' );
        } else {
            $this->update_row( $row['id'], array(
                'status'        => 'failed',
                'attempts'      => $attempts,
                'error_message' => $message,
            ) );
            $this->plugin->log( "Row {$row['id']} failed: {$message}", 'ERROR' );
            $this->email_failure( $row, $message );
        }
        return $error;
    }

    private function email_failure( $row, $message ) {
        if ( ! apply_filters( 'fediverse_posting_email_failures', true, $row ) ) {
            return;
        }
        $title = $row['post_id'] ? get_the_title( $row['post_id'] ) : __( '(post without a WordPress post)', 'wp-fediverse-posting' );
        /* translators: %s: site name */
        $subject = sprintf( __( '[%s] A fediverse post failed', 'wp-fediverse-posting' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
        $body    = sprintf(
            /* translators: 1: post title, 2: error message, 3: Planner URL */
            __( "This post could not be shared on the fediverse:\n\n%1\$s\n\nReason: %2\$s\n\nRetry or edit it in the Planner:\n%3\$s", 'wp-fediverse-posting' ),
            wp_specialchars_decode( $title, ENT_QUOTES ),
            $message,
            admin_url( 'admin.php?page=fediverse-posting' )
        );
        wp_mail( get_option( 'admin_email' ), $subject, $body );
    }


    // =====================================================================
    //  POST LIFECYCLE
    // =====================================================================

    /**
     * Into publish: release waiting rows (or create one from the defaults when
     * "post when published" is on and the post has none). Out of publish
     * (draft, private, trash…): pause scheduled rows.
     */
    public function on_transition_post_status( $new, $old, $post ) {
        if ( $new === $old || ! in_array( $post->post_type, $this->plugin->post_types(), true ) ) {
            return;
        }

        if ( 'publish' === $new ) {
            if ( '' !== $post->post_password ) {
                return;
            }
            $waiting = $this->get_rows( array( 'post_id' => $post->ID, 'statuses' => array( 'waiting' ) ) );

            if ( empty( $waiting ) && $this->plugin->get_setting( 'auto_on_publish' ) && ! get_post_meta( $post->ID, self::SKIP_AUTO_META, true )
                && ! ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) && empty( $this->get_rows( array( 'post_id' => $post->ID ) ) ) ) {
                $id      = $this->insert_row( $this->default_row_data( $post->ID, 'on_publish' ) + array( 'status' => 'waiting' ) );
                $waiting = array( $this->get_row( $id ) );
            }

            foreach ( $waiting as $row ) {
                $this->update_row( $row['id'], array( 'status' => 'scheduled', 'scheduled_at' => self::now_utc() ) );
                wp_schedule_single_event( time(), self::SEND_HOOK, array( $row['id'] ) );
                $this->plugin->log( "Row {$row['id']} released: post {$post->ID} published.", 'INFO' );
            }

            // Queue / fixed-time rows paused while the post was not public resume.
            $paused = $this->get_rows( array( 'post_id' => $post->ID, 'statuses' => array( 'paused' ) ) );
            foreach ( $paused as $row ) {
                $data = array( 'status' => 'scheduled' );
                if ( 'at' !== $row['trigger_type'] || ! $row['scheduled_at'] || strtotime( $row['scheduled_at'] . ' UTC' ) < time() ) {
                    $data['scheduled_at'] = 'queue' === $row['trigger_type'] ? null : self::now_utc();
                }
                $this->update_row( $row['id'], $data );
            }
            if ( $paused ) {
                $this->recalculate_schedule();
            }
            return;
        }

        if ( 'publish' === $old ) {
            global $wpdb;
            $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table_name() . " SET status = 'paused', updated_at = %s WHERE post_id = %d AND status = 'scheduled'", self::now_utc(), $post->ID ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
    }

    /** Post deleted for good: drop rows that never went out, keep history. */
    public function on_delete_post( $post_id ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table_name() . " WHERE post_id = %d AND status NOT IN ('published','removed','processing')", $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /** Row fields from the Settings defaults. */
    public function default_row_data( $post_id, $trigger ) {
        $type = (string) $this->plugin->get_setting( 'default_content_type' );
        return array(
            'post_id'      => (int) $post_id,
            'content_type' => array_key_exists( $type, Fediverse_Posting::content_types() ) ? $type : Fediverse_Posting::DEFAULT_CONTENT_TYPE,
            'template'     => $this->plugin->default_template_for( (string) get_post_type( $post_id ) ),
            'visibility'   => (string) $this->plugin->get_setting( 'default_visibility' ),
            'language'     => '',
            'trigger_type' => $trigger,
        );
    }


    // =====================================================================
    //  CLEANUP, CRON
    // =====================================================================

    public function cleanup() {
        global $wpdb;
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - self::HISTORY_DAYS * DAY_IN_SECONDS );
        $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table_name() . " WHERE status IN ('published','failed','removed') AND updated_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $this->plugin->trim_log();
    }

    public static function add_cron_interval( $schedules ) {
        $schedules[ self::CRON_INTERVAL ] = array(
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => __( 'Every 5 minutes (Fediverse queue)', 'wp-fediverse-posting' ),
        );
        return $schedules;
    }

    /** Recreate the recurring events if something cleared them. */
    public static function ensure_cron() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 60, self::CRON_INTERVAL, self::CRON_HOOK );
        }
        if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
        }
    }

    public static function activate() {
        self::create_table();
        self::ensure_cron();
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
        wp_clear_scheduled_hook( self::CLEANUP_HOOK );
        wp_unschedule_hook( self::SEND_HOOK );
    }
}
