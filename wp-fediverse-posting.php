<?php
/**
 * Plugin Name:       Fediverse Posting for WordPress
 * Plugin URI:        https://github.com/tiagogon/wp-fediverse-posting
 * Description:       Share posts on Mastodon (or any Mastodon-compatible server) and Bluesky: post now, when a post is published, or from a queue with weekly time slots.
 * Version:           1.1.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Tiago Gonçalves
 * Author URI:        https://tiags.space
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-fediverse-posting
 * Domain Path:       /languages
 * Update URI:        https://github.com/tiagogon/wp-fediverse-posting
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'FEDIVERSE_POSTING_VERSION', '1.1.0' );
define( 'FEDIVERSE_POSTING_FILE', __FILE__ );
define( 'FEDIVERSE_POSTING_DIR', plugin_dir_path( __FILE__ ) );
define( 'FEDIVERSE_POSTING_URL', plugin_dir_url( __FILE__ ) );

require_once FEDIVERSE_POSTING_DIR . 'includes/trait-settings-page.php';
require_once FEDIVERSE_POSTING_DIR . 'includes/trait-oauth.php';
require_once FEDIVERSE_POSTING_DIR . 'includes/trait-mastodon-api.php';
require_once FEDIVERSE_POSTING_DIR . 'includes/trait-content.php';
require_once FEDIVERSE_POSTING_DIR . 'includes/trait-queue-ajax.php';
require_once FEDIVERSE_POSTING_DIR . 'includes/trait-queue-editor.php';
require_once FEDIVERSE_POSTING_DIR . 'includes/networks/class-network.php';
require_once FEDIVERSE_POSTING_DIR . 'includes/networks/class-network-mastodon.php';
require_once FEDIVERSE_POSTING_DIR . 'includes/networks/class-bluesky-text.php';
require_once FEDIVERSE_POSTING_DIR . 'includes/networks/class-bluesky-session.php';
require_once FEDIVERSE_POSTING_DIR . 'includes/networks/class-network-bluesky.php';
require_once FEDIVERSE_POSTING_DIR . 'fediverse-queue.php';
require_once FEDIVERSE_POSTING_DIR . 'fediverse-queue-settings.php';

/**
 * Main plugin class: settings, the networks (Mastodon, Bluesky), content
 * rendering. The queue (table, schedule, cron, editor box, AJAX) lives in
 * Fediverse_Queue; each network's HTTP code in includes/networks/.
 *
 * Trait names carry a Fediverse_ prefix on purpose: the sibling Instagram
 * plugin declares unprefixed traits (Settings_Page_Trait…) and both plugins
 * may be active on the same site.
 */
class Fediverse_Posting {

    use Fediverse_Settings_Page_Trait;
    use Fediverse_Content_Trait;

    const OPTION_NAME  = 'fediverse_posting_settings';
    const OPTION_GROUP = 'fediverse_posting_settings_group';
    const APPS_OPTION  = 'fediverse_posting_apps';
    const LOG_DIR      = 'wp-fediverse-posting';
    const LOG_FILE     = 'debug.log';

    const SETTINGS_VERSION     = 2;
    const DEFAULT_TEMPLATE     = '[post-title] [post-url]';
    const DEFAULT_CONTENT_TYPE = 'link_image';
    const DEFAULT_POST_TYPES   = array( 'post' );

    /** @var Fediverse_Queue */
    public $queue;

    /** @var Fediverse_Network[] keyed by id, in display order */
    private $networks = array();

    public function __construct() {
        foreach ( array( new Fediverse_Network_Mastodon( $this ), new Fediverse_Network_Bluesky( $this ) ) as $network ) {
            $this->networks[ $network->id() ] = $network;
        }
        /** Experimental: add a Fediverse_Network implementation. Keyed by id. */
        $this->networks = apply_filters( 'fediverse_posting_networks', $this->networks );
        foreach ( $this->networks as $network ) {
            $network->register_hooks();
        }

        $this->queue = new Fediverse_Queue( $this );

        add_action( 'plugins_loaded', array( __CLASS__, 'maybe_migrate_settings' ), 5 );
        add_action( 'init', array( $this, 'load_textdomain' ) );
        add_action( 'admin_menu', array( $this, 'add_admin_pages' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_admin_assets' ) );
        add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_link' ), 100 );
        add_action( 'admin_notices', array( $this, 'settings_notices' ) );
        add_action( 'wp_ajax_fediverse_clear_log', array( $this, 'ajax_clear_log' ) );

        add_filter( 'plugin_action_links_' . plugin_basename( FEDIVERSE_POSTING_FILE ), array( $this, 'plugin_action_links' ) );
    }

    public function load_textdomain() {
        load_plugin_textdomain( 'wp-fediverse-posting', false, dirname( plugin_basename( FEDIVERSE_POSTING_FILE ) ) . '/languages' );
    }


    // =====================================================================
    //  NETWORKS
    // =====================================================================

    /** @return Fediverse_Network[] keyed by id. */
    public function networks() {
        return $this->networks;
    }

    /** @return Fediverse_Network|null */
    public function network( $id ) {
        return isset( $this->networks[ $id ] ) ? $this->networks[ $id ] : null;
    }

    /** @return Fediverse_Network[] keyed by id. */
    public function connected_networks() {
        return array_filter( $this->networks, function ( $n ) {
            return $n->is_connected();
        } );
    }

    /** At least one network is connected. */
    public function is_connected() {
        return ! empty( $this->connected_networks() );
    }

    /**
     * Network ids a new row is sent to: the Settings choice, limited to the
     * connected networks; nothing chosen (or nothing left) = every connected one.
     */
    public function default_destinations() {
        $connected = array_keys( $this->connected_networks() );
        $chosen    = array_values( array_intersect( (array) $this->get_setting( 'default_destinations' ), $connected ) );
        return $chosen ? $chosen : $connected;
    }

    /**
     * Limits used when a caller has no network in mind: the first connected
     * network's, else Mastodon's defaults.
     */
    public function default_limits() {
        $connected = $this->connected_networks();
        $network   = $connected ? reset( $connected ) : $this->network( 'mastodon' );
        return $network->limits();
    }


    // =====================================================================
    //  SETTINGS ACCESS
    // =====================================================================

    /**
     * Defaults for every stored key. A key absent from the option falls back
     * to these, so a fresh install works without visiting Settings.
     */
    public static function defaults() {
        return array(
            'settings_version'         => self::SETTINGS_VERSION,
            'networks'                 => self::network_defaults(),
            'default_destinations'     => array(),
            'network_templates'        => array(),
            'bluesky_sensitive_label'  => 'graphic-media',
            'post_types'               => self::DEFAULT_POST_TYPES,
            'default_template'         => self::DEFAULT_TEMPLATE,
            'post_type_templates'      => array(),
            'hashtag_exclude'          => array(),
            'default_content_type'     => self::DEFAULT_CONTENT_TYPE,
            'default_visibility'       => 'public',
            'default_language'         => '',
            'auto_on_publish'          => 0,
            'menu_position'            => 'after_settings',
            'debug_logging'            => 0,
            'delete_data_on_uninstall' => 0,
            'queue_enabled'            => 1,
            'queue_schedule'           => array(),
        );
    }

    /** Per-network connection keys. Volatile session state lives elsewhere. */
    public static function network_defaults() {
        return array(
            'mastodon' => array( 'instance' => '', 'access_token' => '', 'account' => array() ),
            'bluesky'  => array( 'method' => 'app_password', 'handle' => '', 'app_password' => '', 'pds' => '' ),
        );
    }

    public static function get_settings() {
        $opts = get_option( self::OPTION_NAME, array() );
        return array_merge( self::defaults(), is_array( $opts ) ? $opts : array() );
    }

    public function get_setting( $key ) {
        $opts = self::get_settings();
        return isset( $opts[ $key ] ) ? $opts[ $key ] : null;
    }

    /**
     * Write a subset of keys, keeping the rest. The option is never autoloaded:
     * it holds the tokens and is only needed on admin screens and cron.
     */
    public static function update_settings( $changes ) {
        $opts = get_option( self::OPTION_NAME, null );
        if ( null === $opts ) {
            add_option( self::OPTION_NAME, array_merge( self::defaults(), $changes ), '', false );
            return;
        }
        update_option( self::OPTION_NAME, array_merge( is_array( $opts ) ? $opts : array(), $changes ), false );
    }

    /** One network's stored settings merged with its defaults. */
    public function network_settings( $id ) {
        $all      = (array) $this->get_setting( 'networks' );
        $defaults = self::network_defaults();
        $base     = isset( $defaults[ $id ] ) ? $defaults[ $id ] : array();
        return array_merge( $base, isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? $all[ $id ] : array() );
    }

    public static function update_network_settings( $id, array $changes ) {
        $all = self::get_settings()['networks'];
        $all = is_array( $all ) ? $all : array();
        $all[ $id ] = array_merge( isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? $all[ $id ] : array(), $changes );
        self::update_settings( array( 'networks' => $all ) );
    }

    /**
     * 1.0 stored the Mastodon connection as flat keys (instance, access_token,
     * account). Move them under networks['mastodon'] once.
     */
    public static function maybe_migrate_settings() {
        $opts = get_option( self::OPTION_NAME, null );
        if ( ! is_array( $opts ) || ( isset( $opts['settings_version'] ) && (int) $opts['settings_version'] >= self::SETTINGS_VERSION ) ) {
            return;
        }
        $networks = isset( $opts['networks'] ) && is_array( $opts['networks'] ) ? $opts['networks'] : array();
        $mastodon = isset( $networks['mastodon'] ) && is_array( $networks['mastodon'] ) ? $networks['mastodon'] : array();
        foreach ( array( 'instance', 'access_token', 'account' ) as $key ) {
            if ( isset( $opts[ $key ] ) && ! isset( $mastodon[ $key ] ) ) {
                $mastodon[ $key ] = $opts[ $key ];
            }
            unset( $opts[ $key ] );
        }
        $networks['mastodon']     = $mastodon;
        $opts['networks']         = $networks;
        $opts['settings_version'] = self::SETTINGS_VERSION;
        update_option( self::OPTION_NAME, $opts, false );
    }

    /**
     * Post types that get the Fediverse box. Key never saved → default
     * ('post'); saved empty → none. Filter: fediverse_posting_post_types.
     */
    public function post_types() {
        $types = (array) $this->get_setting( 'post_types' );
        $types = apply_filters( 'fediverse_posting_post_types', $types );
        return array_values( array_filter( array_map( 'sanitize_key', (array) $types ) ) );
    }

    /** Own text set for a post type in Settings, or '' when it uses the default. */
    public function own_template_for( $post_type ) {
        $all = (array) $this->get_setting( 'post_type_templates' );
        return isset( $all[ $post_type ] ) && '' !== trim( (string) $all[ $post_type ] ) ? (string) $all[ $post_type ] : '';
    }

    /** Starting text for new fediverse posts of a post type. */
    public function default_template_for( $post_type ) {
        $own = $this->own_template_for( $post_type );
        return '' !== $own ? $own : (string) $this->get_setting( 'default_template' );
    }

    /**
     * A network's own starting text for a post type (Settings → "Bluesky text
     * per post type"), or '' when that network uses the common text.
     */
    public function network_template_for( $network_id, $post_type ) {
        $all = (array) $this->get_setting( 'network_templates' );
        if ( empty( $all[ $network_id ][ $post_type ] ) ) {
            return '';
        }
        $text = (string) $all[ $network_id ][ $post_type ];
        return '' !== trim( $text ) ? $text : '';
    }

    /** Capability needed to compose and send. Filter: fediverse_posting_capability. */
    public static function compose_capability() {
        return apply_filters( 'fediverse_posting_capability', 'publish_posts' );
    }

    /** Capability for the Settings page. Filter: fediverse_posting_settings_capability. */
    public static function settings_capability() {
        return apply_filters( 'fediverse_posting_settings_capability', 'manage_options' );
    }

    public static function settings_url( $args = array() ) {
        return add_query_arg( $args, admin_url( 'admin.php?page=fediverse-posting-settings' ) );
    }


    // =====================================================================
    //  LOGGING (file in uploads, protected; errors always, the rest on debug)
    // =====================================================================

    private function get_log_path() {
        $upload_dir = wp_upload_dir();
        return trailingslashit( $upload_dir['basedir'] ) . self::LOG_DIR . '/' . self::LOG_FILE;
    }

    private function ensure_log_directory() {
        $dir = dirname( $this->get_log_path() );
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
            file_put_contents( $dir . '/.htaccess', "Deny from all\n" );
            file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
        }
    }

    /**
     * @param string $message
     * @param string $level 'INFO' | 'DEBUG' | 'ERROR'
     */
    public function log( $message, $level = 'INFO' ) {
        if ( 'ERROR' !== $level && ! $this->get_setting( 'debug_logging' ) ) {
            return;
        }
        $this->ensure_log_directory();
        $line = '[' . gmdate( 'Y-m-d H:i:s' ) . "] [{$level}] {$message}\n";
        file_put_contents( $this->get_log_path(), $line, FILE_APPEND | LOCK_EX );
    }

    public function read_log_tail( $lines = 100 ) {
        $path = $this->get_log_path();
        if ( ! file_exists( $path ) ) {
            return __( 'No log entries yet.', 'wp-fediverse-posting' );
        }
        $all = file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        if ( empty( $all ) ) {
            return __( 'Log is empty.', 'wp-fediverse-posting' );
        }
        return implode( "\n", array_slice( $all, -$lines ) );
    }

    /** Keep the log small: called by the daily cleanup. */
    public function trim_log( $keep = 1000 ) {
        $path = $this->get_log_path();
        if ( ! file_exists( $path ) ) {
            return;
        }
        $all = file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        if ( is_array( $all ) && count( $all ) > $keep ) {
            file_put_contents( $path, implode( "\n", array_slice( $all, -$keep ) ) . "\n", LOCK_EX );
        }
    }

    public function ajax_clear_log() {
        check_ajax_referer( 'fediverse_clear_log', 'nonce' );
        if ( ! current_user_can( self::settings_capability() ) ) {
            wp_send_json_error( __( 'Not allowed.', 'wp-fediverse-posting' ), 403 );
        }
        $path = $this->get_log_path();
        if ( file_exists( $path ) ) {
            file_put_contents( $path, '' );
        }
        wp_send_json_success();
    }


    // =====================================================================
    //  ACTIVATION
    // =====================================================================

    public static function activate() {
        if ( null === get_option( self::OPTION_NAME, null ) ) {
            add_option( self::OPTION_NAME, self::defaults(), '', false );
        }
        self::maybe_migrate_settings();
        Fediverse_Queue::activate();
    }

    public static function deactivate() {
        Fediverse_Queue::deactivate();
    }
}

register_activation_hook( __FILE__, array( 'Fediverse_Posting', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Fediverse_Posting', 'deactivate' ) );

$GLOBALS['fediverse_posting'] = new Fediverse_Posting();
