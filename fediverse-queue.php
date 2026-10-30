<?php
/**
 * Fediverse_Queue: every planned post is one row in {prefix}fediverse_queue,
 * sent to one or more networks. Each network gets a target row in
 * {prefix}fediverse_queue_targets with its own outcome.
 *
 * Rows and their life:
 *   trigger_type  now | on_publish | queue | at
 *   status        waiting   (on_publish, until the WP post goes public)
 *                 scheduled (has scheduled_at; the cron sends it when due)
 *                 processing → published | failed
 *                 paused    (its WP post left `publish` or went to the trash)
 *                 removed   (every target deleted on its server from here)
 *
 * Targets: pending | published | failed | removed. The ROW owns time (slot,
 * trigger, pause); a target only records an outcome, its own attempts and
 * retry back-off (retry_at), the remote id and URL.
 *
 * Guarantees:
 *   - A row is claimed with one UPDATE … WHERE status='scheduled' before it is
 *     sent, so two cron runs (or cron + "Post now") can never both send it.
 *   - Each target is updated right after its own publish, so a row stuck in
 *     `processing` (PHP died) can go back to `scheduled`: only targets still
 *     pending are sent again. Mastodon sends carry Idempotency-Key
 *     "wpfp-<site>-<row id>" on top; Bluesky looks for an identical recent
 *     post when a target is sent a second time.
 *   - Templates are filled in at send time (Fediverse_Content_Trait), with the
 *     network's own text when the row has one (network_templates).
 *   - A network that is not connected does not fail a target: the row waits
 *     (process_queue only picks rows with a due target on a connected network).
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
    const TARGETS_TABLE = 'fediverse_queue_targets';
    const DB_VERSION    = '1.1';
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
    //  TABLES
    // =====================================================================

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function targets_table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TARGETS_TABLE;
    }

    /**
     * `trigger` and `sensitive` are reserved words in MySQL, hence trigger_type
     * and is_sensitive. remote_id / remote_url on the row are 1.0 leftovers:
     * targets hold them now (dbDelta cannot drop columns).
     */
    public static function create_table() {
        global $wpdb;
        $table   = self::table_name();
        $targets = self::targets_table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            content_type varchar(20) NOT NULL DEFAULT 'link_image',
            template text NOT NULL,
            network_templates longtext NULL,
            gallery_ids text NULL,
            alt_overrides text NULL,
            visibility varchar(20) NOT NULL DEFAULT 'public',
            language varchar(10) NOT NULL DEFAULT '',
            spoiler_text varchar(500) NOT NULL DEFAULT '',
            is_sensitive tinyint(1) NOT NULL DEFAULT 0,
            sensitive_label varchar(20) NOT NULL DEFAULT '',
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
        ) {$charset};
        CREATE TABLE {$targets} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            row_id bigint(20) unsigned NOT NULL,
            network varchar(20) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
            retry_at datetime NULL DEFAULT NULL,
            error_message text NULL,
            remote_id varchar(255) NOT NULL DEFAULT '',
            remote_url varchar(500) NOT NULL DEFAULT '',
            published_at datetime NULL DEFAULT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY row_network (row_id,network),
            KEY status_network (status,network,retry_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        self::migrate_targets();
        update_option( self::DB_OPTION, self::DB_VERSION, false );
    }

    /** 1.0 rows (one Mastodon post each) get their `mastodon` target. Idempotent. */
    private static function migrate_targets() {
        global $wpdb;
        $table   = self::table_name();
        $targets = self::targets_table_name();
        $wpdb->query( "INSERT INTO {$targets} (row_id, network, status, attempts, error_message, remote_id, remote_url, published_at, updated_at)
            SELECT q.id, 'mastodon',
                CASE q.status WHEN 'published' THEN 'published' WHEN 'failed' THEN 'failed' WHEN 'removed' THEN 'removed' ELSE 'pending' END,
                q.attempts, q.error_message, q.remote_id, q.remote_url, q.published_at, q.updated_at
            FROM {$table} q
            WHERE NOT EXISTS (SELECT 1 FROM {$targets} t WHERE t.row_id = q.id)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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

    /** Decode a DB row into typed values. Targets are attached separately. */
    private static function hydrate( $row ) {
        if ( ! $row ) {
            return null;
        }
        $row                      = (array) $row;
        $row['id']                = (int) $row['id'];
        $row['post_id']           = (int) $row['post_id'];
        $row['is_sensitive']      = (int) $row['is_sensitive'];
        $row['position']          = (int) $row['position'];
        $row['attempts']          = (int) $row['attempts'];
        $gallery                  = json_decode( (string) $row['gallery_ids'], true );
        $row['gallery_ids']       = is_array( $gallery ) ? array_map( 'intval', $gallery ) : array();
        $alts                     = json_decode( (string) $row['alt_overrides'], true );
        $row['alt_overrides']     = is_array( $alts ) ? $alts : array();
        $templates                = json_decode( (string) $row['network_templates'], true );
        $row['network_templates'] = is_array( $templates ) ? array_map( 'strval', $templates ) : array();
        $row['sensitive_label']   = isset( $row['sensitive_label'] ) ? (string) $row['sensitive_label'] : '';
        $row['targets']           = array();
        return $row;
    }

    private static function hydrate_target( $t ) {
        $t             = (array) $t;
        $t['id']       = (int) $t['id'];
        $t['row_id']   = (int) $t['row_id'];
        $t['attempts'] = (int) $t['attempts'];
        return $t;
    }

    /** One query for every row's targets; sets $row['targets'] keyed by network. */
    private function attach_targets( array $rows ) {
        global $wpdb;
        if ( empty( $rows ) ) {
            return $rows;
        }
        $ids = array_map( function ( $r ) {
            return (int) $r['id'];
        }, $rows );
        $sql  = 'SELECT * FROM ' . self::targets_table_name() . ' WHERE row_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY id ASC';
        $all  = (array) $wpdb->get_results( $wpdb->prepare( $sql, $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $byId = array();
        foreach ( $all as $t ) {
            $t = self::hydrate_target( $t );
            $byId[ $t['row_id'] ][ $t['network'] ] = $t;
        }
        foreach ( $rows as &$row ) {
            $row['targets'] = isset( $byId[ $row['id'] ] ) ? $byId[ $row['id'] ] : array();
        }
        unset( $row );
        return $rows;
    }

    public function get_row( $id ) {
        global $wpdb;
        $table = self::table_name();
        $row   = self::hydrate( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( ! $row ) {
            return null;
        }
        $rows = $this->attach_targets( array( $row ) );
        return $rows[0];
    }

    /** @return array Targets of a row keyed by network. */
    public function get_targets( $row_id ) {
        $rows = $this->attach_targets( array( array( 'id' => (int) $row_id ) ) );
        return $rows[0]['targets'];
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

        $rows = array_map( array( __CLASS__, 'hydrate' ), (array) $wpdb->get_results( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $this->attach_targets( $rows );
    }

    public function count_failed() {
        global $wpdb;
        $table = self::table_name();
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'failed'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /** JSON-encode the array columns in place. */
    private static function encode_columns( array $data ) {
        if ( array_key_exists( 'gallery_ids', $data ) ) {
            $data['gallery_ids'] = wp_json_encode( array_values( array_map( 'intval', (array) $data['gallery_ids'] ) ) );
        }
        if ( array_key_exists( 'alt_overrides', $data ) ) {
            $data['alt_overrides'] = wp_json_encode( (object) $data['alt_overrides'] );
        }
        if ( array_key_exists( 'network_templates', $data ) ) {
            $data['network_templates'] = wp_json_encode( (object) array_filter( array_map( 'strval', (array) $data['network_templates'] ), 'strlen' ) );
        }
        return $data;
    }

    /**
     * @param array $data Row columns plus `destinations` (network ids; absent = the Settings default).
     * @return int New row id.
     */
    public function insert_row( $data ) {
        global $wpdb;
        $now          = self::now_utc();
        $destinations = array_key_exists( 'destinations', $data ) ? (array) $data['destinations'] : $this->plugin->default_destinations();
        unset( $data['destinations'] );
        $data = array_merge( array(
            'post_id'           => 0,
            'content_type'      => Fediverse_Posting::DEFAULT_CONTENT_TYPE,
            'template'          => '',
            'network_templates' => array(),
            'gallery_ids'       => array(),
            'alt_overrides'     => array(),
            'visibility'        => 'public',
            'language'          => '',
            'spoiler_text'      => '',
            'is_sensitive'      => 0,
            'sensitive_label'   => '',
            'trigger_type'      => 'queue',
            'position'          => 0,
            'scheduled_at'      => null,
            'status'            => 'scheduled',
            'created_by'        => get_current_user_id(),
            'created_at'        => $now,
            'updated_at'        => $now,
        ), $data );
        $wpdb->insert( self::table_name(), self::encode_columns( $data ) );
        $id = (int) $wpdb->insert_id;
        $this->sync_targets( $id, $destinations );
        return $id;
    }

    /** @param array $data Row columns; `destinations` (network ids) syncs the targets. */
    public function update_row( $id, $data ) {
        global $wpdb;
        if ( array_key_exists( 'destinations', $data ) ) {
            $this->sync_targets( (int) $id, (array) $data['destinations'] );
            unset( $data['destinations'] );
        }
        $data['updated_at'] = self::now_utc();
        return false !== $wpdb->update( self::table_name(), self::encode_columns( $data ), array( 'id' => (int) $id ) );
    }

    /**
     * Make the row's targets match the ticked networks: new ones start
     * pending, failed ones ticked again go back to pending (the row was just
     * edited), pending ones unticked disappear. Published and removed targets
     * are history and are never touched here.
     */
    private function sync_targets( $row_id, array $destinations ) {
        global $wpdb;
        $table        = self::targets_table_name();
        $destinations = array_values( array_unique( array_filter( array_map( 'sanitize_key', $destinations ) ) ) );
        $now          = self::now_utc();
        $existing     = $this->get_targets( $row_id );

        foreach ( $destinations as $network ) {
            if ( ! isset( $existing[ $network ] ) ) {
                $wpdb->insert( $table, array( 'row_id' => (int) $row_id, 'network' => $network, 'status' => 'pending', 'updated_at' => $now ) );
            } elseif ( 'failed' === $existing[ $network ]['status'] ) {
                $this->update_target( $existing[ $network ]['id'], array( 'status' => 'pending', 'attempts' => 0, 'retry_at' => null, 'error_message' => null ) );
            }
        }
        foreach ( $existing as $network => $target ) {
            if ( 'pending' === $target['status'] && ! in_array( $network, $destinations, true ) ) {
                $wpdb->delete( $table, array( 'id' => $target['id'] ), array( '%d' ) );
            }
        }
    }

    /** A row with no targets at all (should not happen) gets the default ones. */
    private function ensure_targets( array &$row ) {
        if ( empty( $row['targets'] ) ) {
            $this->sync_targets( $row['id'], $this->plugin->default_destinations() );
            $row['targets'] = $this->get_targets( $row['id'] );
        }
    }

    public function update_target( $target_id, array $data ) {
        global $wpdb;
        $data['updated_at'] = self::now_utc();
        return false !== $wpdb->update( self::targets_table_name(), $data, array( 'id' => (int) $target_id ) );
    }

    public function delete_row( $id ) {
        global $wpdb;
        $wpdb->delete( self::targets_table_name(), array( 'row_id' => (int) $id ), array( '%d' ) );
        $wpdb->delete( self::table_name(), array( 'id' => (int) $id ), array( '%d' ) );
    }

    /** Targets whose row is gone (post deleted, history cleaned). */
    private function delete_orphan_targets() {
        global $wpdb;
        $wpdb->query( 'DELETE t FROM ' . self::targets_table_name() . ' t LEFT JOIN ' . self::table_name() . ' q ON q.id = t.row_id WHERE q.id IS NULL' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
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
        $table   = self::table_name();
        $targets = self::targets_table_name();
        $now     = self::now_utc();

        // PHP died mid-send: put the row back. Its pending targets count one
        // attempt, so Bluesky checks for an identical recent post first.
        $stuck = gmdate( 'Y-m-d H:i:s', time() - self::STUCK_MINUTES * MINUTE_IN_SECONDS );
        $wpdb->query( $wpdb->prepare( "UPDATE {$targets} t JOIN {$table} q ON q.id = t.row_id SET t.attempts = GREATEST(t.attempts, 1), t.updated_at = %s WHERE q.status = 'processing' AND q.updated_at < %s AND t.status = 'pending'", $now, $stuck ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'scheduled', updated_at = %s WHERE status = 'processing' AND updated_at < %s", $now, $stuck ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $connected = array_keys( $this->plugin->connected_networks() );
        if ( empty( $connected ) ) {
            return;
        }

        $paused_triggers = $this->is_enabled() ? '' : " AND q.trigger_type NOT IN ('queue','at')";
        $in              = implode( ',', array_fill( 0, count( $connected ), '%s' ) );
        $sql             = "SELECT q.id FROM {$table} q JOIN {$targets} t ON t.row_id = q.id
            WHERE q.status = 'scheduled' AND q.scheduled_at IS NOT NULL AND q.scheduled_at <= %s{$paused_triggers}
              AND t.status = 'pending' AND t.network IN ({$in}) AND (t.retry_at IS NULL OR t.retry_at <= %s)
            ORDER BY q.scheduled_at ASC, q.id ASC LIMIT 1";
        $id = $wpdb->get_var( $wpdb->prepare( $sql, array_merge( array( $now ), $connected, array( $now ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        if ( $id ) {
            $this->send_row( (int) $id );
        }
    }

    /**
     * Claim and send one row to every due target. Safe to call from cron, a
     * single event and AJAX.
     *
     * @return array|WP_Error { id, url (first published), published (network => result), failed (network => message) }
     */
    public function send_row( $id ) {
        global $wpdb;
        $table     = self::table_name();
        $connected = $this->plugin->connected_networks();

        // Leave the row as it is: it goes out once an account is connected.
        if ( empty( $connected ) ) {
            return new WP_Error( 'fediverse_not_connected', __( 'No account is connected. Connect one in Fediverse → Settings.', 'wp-fediverse-posting' ) );
        }

        $claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'processing', updated_at = %s WHERE id = %d AND status = 'scheduled'", self::now_utc(), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( 1 !== (int) $claimed ) {
            return new WP_Error( 'fediverse_claimed', __( 'This post is already being sent or is not scheduled.', 'wp-fediverse-posting' ) );
        }

        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
        ignore_user_abort( true );

        $row = $this->get_row( $id );
        $this->ensure_targets( $row );
        $post = null;

        if ( $row['post_id'] ) {
            $post = get_post( $row['post_id'] );
            if ( ! $post || 'publish' !== $post->post_status || '' !== $post->post_password ) {
                return $this->fail( $row, new WP_Error( 'fediverse_not_public', __( 'The WordPress post is not public (draft, private, password-protected or deleted).', 'wp-fediverse-posting' ) ) );
            }
        }

        $now = time();
        $due = array_filter( $row['targets'], function ( $t ) use ( $connected, $now ) {
            return 'pending' === $t['status'] && isset( $connected[ $t['network'] ] )
                && ( ! $t['retry_at'] || strtotime( $t['retry_at'] . ' UTC' ) <= $now );
        } );
        if ( empty( $due ) ) {
            $this->settle_row( $row );
            return new WP_Error( 'fediverse_nothing_due', __( 'Nothing to send right now: the remaining networks are not connected or are waiting to retry.', 'wp-fediverse-posting' ) );
        }

        $out = array( 'id' => '', 'url' => '', 'published' => array(), 'failed' => array() );
        foreach ( $due as $network_id => $target ) {
            $result = $this->send_target( $row, $target, $post, $connected[ $network_id ] );
            if ( is_wp_error( $result ) ) {
                $out['failed'][ $network_id ] = $result->get_error_message();
            } else {
                $out['published'][ $network_id ] = $result;
                if ( '' === $out['url'] ) {
                    $out['id']  = $result['id'];
                    $out['url'] = $result['url'];
                }
            }
        }

        $this->settle_row( $row );

        if ( empty( $out['published'] ) ) {
            return new WP_Error( 'fediverse_send_failed', implode( ' ', $this->network_messages( $out['failed'] ) ), $out );
        }
        return $out;
    }

    /** "Bluesky: message" lines. */
    private function network_messages( array $by_network ) {
        $lines = array();
        foreach ( $by_network as $network_id => $message ) {
            $network = $this->plugin->network( $network_id );
            $lines[] = ( $network ? $network->label() : $network_id ) . ': ' . $message;
        }
        return $lines;
    }

    /** Render, prepare the images and publish one target. */
    private function send_target( array $row, array $target, $post, Fediverse_Network $network ) {
        $plugin = $this->plugin;
        $limits = $network->limits();

        $text  = $plugin->render_template( $network->template_for_row( $row ), $post );
        $media = $plugin->resolve_media_ids( $row, $post, $limits );
        if ( '' === $text && empty( $media ) ) {
            return $this->fail_target( $row, $target, new WP_Error( 'fediverse_empty', __( 'Nothing to send: the text is empty and there are no images.', 'wp-fediverse-posting' ), array( 'retryable' => false ) ) );
        }

        $chars = $network->count_characters( $text );
        if ( $chars > $limits['max_characters'] ) {
            if ( $limits['hard_limit'] ) {
                /* translators: 1: characters used, 2: server limit */
                return $this->fail_target( $row, $target, new WP_Error( 'fediverse_too_long', sprintf( __( 'The text is %1$d characters; the server allows %2$d.', 'wp-fediverse-posting' ), $chars, $limits['max_characters'] ), array( 'retryable' => false ) ) );
            }
            $text = $network->truncate( $text, $limits['max_characters'] );
            $plugin->log( "Row {$row['id']}/{$network->id()}: text shortened from {$chars} to {$limits['max_characters']}.", 'INFO' );
        }

        $files = array();
        foreach ( $media as $att_id ) {
            $file = $plugin->prepare_media_file( $att_id, $limits );
            if ( is_wp_error( $file ) ) {
                $this->delete_temp_files( $files );
                return $this->fail_target( $row, $target, $file );
            }
            $file['attachment_id'] = $att_id;
            $file['alt']           = $plugin->alt_text_for( $att_id, $row, $limits['alt_max'] );
            $files[]               = $file;
        }

        $result = $network->publish( $row, $post, $text, $files, array( 'attempts' => $target['attempts'] ) );
        $this->delete_temp_files( $files );
        if ( is_wp_error( $result ) ) {
            return $this->fail_target( $row, $target, $result );
        }

        // First thing after success, so a crash from here on can never resend it.
        $this->update_target( $target['id'], array(
            'status'        => 'published',
            'remote_id'     => $result['id'],
            'remote_url'    => $result['url'],
            'published_at'  => self::now_utc(),
            'attempts'      => $target['attempts'] + 1,
            'retry_at'      => null,
            'error_message' => null,
        ) );
        if ( $row['post_id'] ) {
            update_post_meta( $row['post_id'], self::POSTED_META, time() );
        }
        $plugin->log( "Row {$row['id']} published on {$network->id()}: {$result['url']}", 'INFO' );
        /** After a post went out. $result['network'] names the network; so does the 4th argument. */
        do_action( 'fediverse_posting_published', $result, $row, $post, $network->id() );

        return $result;
    }

    private function delete_temp_files( array $files ) {
        foreach ( $files as $file ) {
            if ( ! empty( $file['temp'] ) ) {
                wp_delete_file( $file['path'] );
            }
        }
    }

    public static function idempotency_key( $row_id ) {
        return 'wpfp-' . substr( md5( home_url() ), 0, 12 ) . '-' . (int) $row_id;
    }

    /**
     * One target failed. Network, 429 and 5xx errors are retried (5, 15, 30
     * min) through the target's retry_at; anything else, or the last attempt,
     * marks the target failed. settle_row() then decides the row's state.
     */
    private function fail_target( array $row, array $target, WP_Error $error ) {
        $data      = (array) $error->get_error_data();
        $attempts  = $target['attempts'] + 1;
        $retryable = ! empty( $data['retryable'] ) && $attempts < self::MAX_ATTEMPTS;
        $message   = $error->get_error_message();

        if ( $retryable ) {
            $backoff = array( 1 => 5, 2 => 15 );
            $wait    = isset( $backoff[ $attempts ] ) ? $backoff[ $attempts ] : 30;
            $when    = time() + $wait * MINUTE_IN_SECONDS;
            if ( ! empty( $data['retry_at'] ) && $data['retry_at'] > $when ) {
                $when = (int) $data['retry_at'];
            }
            $this->update_target( $target['id'], array(
                'status'        => 'pending',
                'attempts'      => $attempts,
                'retry_at'      => gmdate( 'Y-m-d H:i:s', $when ),
                /* translators: %s: error message */
                'error_message' => sprintf( __( 'Retrying: %s', 'wp-fediverse-posting' ), $message ),
            ) );
            $this->plugin->log( "Row {$row['id']}/{$target['network']} attempt {$attempts} failed, retrying: {$message}", 'ERROR' );
        } else {
            $this->update_target( $target['id'], array(
                'status'        => 'failed',
                'attempts'      => $attempts,
                'retry_at'      => null,
                'error_message' => $message,
            ) );
            $this->plugin->log( "Row {$row['id']}/{$target['network']} failed: {$message}", 'ERROR' );
        }
        return $error;
    }

    /** The whole row cannot go out (post not public…): every pending target fails. */
    private function fail( array $row, WP_Error $error ) {
        foreach ( $row['targets'] as $target ) {
            if ( 'pending' === $target['status'] ) {
                $this->update_target( $target['id'], array( 'status' => 'failed', 'attempts' => $target['attempts'] + 1, 'retry_at' => null, 'error_message' => $error->get_error_message() ) );
            }
        }
        $this->plugin->log( "Row {$row['id']} failed: " . $error->get_error_message(), 'ERROR' );
        $this->settle_row( $row );
        return $error;
    }

    /**
     * Row state from its targets, after a send pass:
     *   a target waiting to retry        → scheduled at the earliest retry_at
     *   a pending target (not connected) → scheduled, time unchanged
     *   a failed target                  → failed (one email, naming the networks)
     *   all published / removed          → published (removed when all were deleted)
     * Row attempts = the highest target attempts, so recalculate_schedule()
     * keeps leaving retrying rows alone.
     */
    private function settle_row( array $row ) {
        $targets   = $this->get_targets( $row['id'] );
        $retry_at  = array();
        $pending   = 0;
        $failed    = array();
        $published = 0;
        $removed   = 0;
        $attempts  = 0;
        foreach ( $targets as $network_id => $t ) {
            $attempts = max( $attempts, $t['attempts'] );
            switch ( $t['status'] ) {
                case 'pending':
                    $pending++;
                    if ( $t['retry_at'] ) {
                        $retry_at[] = $t['retry_at'];
                    }
                    break;
                case 'failed':
                    $failed[ $network_id ] = (string) $t['error_message'];
                    break;
                case 'published':
                    $published++;
                    break;
                case 'removed':
                    $removed++;
                    break;
            }
        }

        $data = array( 'attempts' => $attempts );
        if ( $retry_at ) {
            sort( $retry_at );
            $data['status']        = 'scheduled';
            $data['scheduled_at']  = $retry_at[0];
            $data['error_message'] = implode( "\n", $this->network_messages( array_filter( array_map( function ( $t ) {
                return 'pending' === $t['status'] && $t['retry_at'] ? (string) $t['error_message'] : '';
            }, $targets ) ) ) );
        } elseif ( $pending ) {
            $data['status']        = 'scheduled';
            $data['error_message'] = null;
        } elseif ( $failed ) {
            $data['status']        = 'failed';
            $data['error_message'] = implode( "\n", $this->network_messages( $failed ) );
            if ( 'failed' !== $row['status'] ) {
                $this->email_failure( $row, $data['error_message'] );
            }
        } elseif ( $removed && ! $published ) {
            $data['status'] = 'removed';
        } else {
            $data['status']        = 'published';
            $data['error_message'] = null;
            if ( empty( $row['published_at'] ) ) {
                $data['published_at'] = self::now_utc();
            }
        }
        $this->update_row( $row['id'], $data );
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
            __( "This post could not be shared:\n\n%1\$s\n\nReason: %2\$s\n\nRetry or edit it in the Planner:\n%3\$s", 'wp-fediverse-posting' ),
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
        $this->delete_orphan_targets();
    }

    /** Row fields from the Settings defaults (targets: default_destinations). */
    public function default_row_data( $post_id, $trigger ) {
        $type      = (string) $this->plugin->get_setting( 'default_content_type' );
        $post_type = (string) get_post_type( $post_id );
        $templates = array();
        foreach ( $this->plugin->networks() as $network ) {
            $own = $this->plugin->network_template_for( $network->id(), $post_type );
            if ( '' !== $own ) {
                $templates[ $network->id() ] = $own;
            }
        }
        return array(
            'post_id'           => (int) $post_id,
            'content_type'      => array_key_exists( $type, Fediverse_Posting::content_types() ) ? $type : Fediverse_Posting::DEFAULT_CONTENT_TYPE,
            'template'          => $this->plugin->default_template_for( $post_type ),
            'network_templates' => $templates,
            'visibility'        => (string) $this->plugin->get_setting( 'default_visibility' ),
            'language'          => '',
            'trigger_type'      => $trigger,
        );
    }


    // =====================================================================
    //  CLEANUP, CRON
    // =====================================================================

    public function cleanup() {
        global $wpdb;
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - self::HISTORY_DAYS * DAY_IN_SECONDS );
        $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table_name() . " WHERE status IN ('published','failed','removed') AND updated_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $this->delete_orphan_targets();
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
