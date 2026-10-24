<?php
/**
 * Plugin Name:       Fediverse Posting for WordPress
 * Plugin URI:        https://github.com/tiagogon/wp-fediverse-posting
 * Description:       Share posts on your Mastodon (or any Mastodon-compatible) account: post now, when a post is published, or from a queue with weekly time slots.
 * Version:           1.0.0
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

define( 'FEDIVERSE_POSTING_VERSION', '1.0.0' );
define( 'FEDIVERSE_POSTING_FILE', __FILE__ );
define( 'FEDIVERSE_POSTING_DIR', plugin_dir_path( __FILE__ ) );
define( 'FEDIVERSE_POSTING_URL', plugin_dir_url( __FILE__ ) );

require_once FEDIVERSE_POSTING_DIR . 'includes/trait-settings-page.php';
require_once FEDIVERSE_POSTING_DIR . 'includes/trait-oauth.php';
require_once FEDIVERSE_POSTING_DIR . 'includes/trait-mastodon-api.php';
require_once FEDIVERSE_POSTING_DIR . 'includes/trait-content.php';
require_once FEDIVERSE_POSTING_DIR . 'includes/trait-queue-ajax.php';
require_once FEDIVERSE_POSTING_DIR . 'includes/trait-queue-editor.php';
require_once FEDIVERSE_POSTING_DIR . 'fediverse-queue.php';
require_once FEDIVERSE_POSTING_DIR . 'fediverse-queue-settings.php';

/**
 * Main plugin class: settings, connection, API client, content rendering.
 * The queue (table, schedule, cron, editor box, AJAX) lives in Fediverse_Queue.
 *
 * Trait names carry a Fediverse_ prefix on purpose: the sibling Instagram
 * plugin declares unprefixed traits (Settings_Page_Trait…) and both plugins
 * may be active on the same site.
 */
class Fediverse_Posting {

    use Fediverse_Settings_Page_Trait;
    use Fediverse_OAuth_Trait;
    use Fediverse_Mastodon_API_Trait;
    use Fediverse_Content_Trait;

    const OPTION_NAME  = 'fediverse_posting_settings';
    const OPTION_GROUP = 'fediverse_posting_settings_group';
    const APPS_OPTION  = 'fediverse_posting_apps';
    const LOG_DIR      = 'wp-fediverse-posting';
    const LOG_FILE     = 'debug.log';

    const DEFAULT_TEMPLATE     = '[post-title] [post-url]';
    const DEFAULT_CONTENT_TYPE = 'link_image';
    const DEFAULT_POST_TYPES   = array( 'post' );

    /** @var Fediverse_Queue */
    public $queue;

    public function __construct() {
        $this->queue = new Fediverse_Queue( $this );

        add_action( 'init', array( $this, 'load_textdomain' ) );
        add_action( 'admin_menu', array( $this, 'add_admin_pages' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_admin_assets' ) );
        add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_link' ), 100 );
        add_action( 'admin_notices', array( $this, 'settings_notices' ) );

        add_action( 'admin_post_fediverse_connect', array( $this, 'handle_connect' ) );
        add_action( 'admin_post_fediverse_oauth_callback', array( $this, 'handle_oauth_callback' ) );
        add_action( 'admin_post_fediverse_save_token', array( $this, 'handle_save_token' ) );
        add_action( 'admin_post_fediverse_disconnect', array( $this, 'handle_disconnect' ) );
        add_action( 'wp_ajax_fediverse_clear_log', array( $this, 'ajax_clear_log' ) );

        add_filter( 'plugin_action_links_' . plugin_basename( FEDIVERSE_POSTING_FILE ), array( $this, 'plugin_action_links' ) );
    }

    public function load_textdomain() {
        load_plugin_textdomain( 'wp-fediverse-posting', false, dirname( plugin_basename( FEDIVERSE_POSTING_FILE ) ) . '/languages' );
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
            'instance'                => '',
            'access_token'            => '',
            'account'                 => array(),
            'post_types'              => self::DEFAULT_POST_TYPES,
            'default_template'        => self::DEFAULT_TEMPLATE,
            'default_content_type'    => self::DEFAULT_CONTENT_TYPE,
            'default_visibility'      => 'public',
            'default_language'        => '',
            'auto_on_publish'         => 0,
            'menu_position'           => 'after_settings',
            'debug_logging'           => 0,
            'delete_data_on_uninstall' => 0,
            'queue_enabled'           => 1,
            'queue_schedule'          => array(),
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
     * it holds the access token and is only needed on admin screens and cron.
     */
    public static function update_settings( $changes ) {
        $opts = get_option( self::OPTION_NAME, null );
        if ( null === $opts ) {
            add_option( self::OPTION_NAME, array_merge( self::defaults(), $changes ), '', false );
            return;
        }
        update_option( self::OPTION_NAME, array_merge( is_array( $opts ) ? $opts : array(), $changes ), false );
    }

    /** Server host (e.g. "ciberlandia.pt"); wp-config constant wins. */
    public function get_instance() {
        if ( defined( 'FEDIVERSE_POSTING_INSTANCE' ) && FEDIVERSE_POSTING_INSTANCE ) {
            return self::normalize_instance( FEDIVERSE_POSTING_INSTANCE );
        }
        return (string) $this->get_setting( 'instance' );
    }

    /** Access token; wp-config constant wins. */
    public function get_token() {
        if ( defined( 'FEDIVERSE_POSTING_TOKEN' ) && FEDIVERSE_POSTING_TOKEN ) {
            return (string) FEDIVERSE_POSTING_TOKEN;
        }
        return (string) $this->get_setting( 'access_token' );
    }

    public static function uses_constants() {
        return defined( 'FEDIVERSE_POSTING_TOKEN' ) && FEDIVERSE_POSTING_TOKEN;
    }

    public function is_connected() {
        return '' !== $this->get_instance() && '' !== $this->get_token();
    }

    /** Connected account as returned by verify_credentials (trimmed). */
    public function get_account() {
        $account = $this->get_setting( 'account' );
        return is_array( $account ) ? $account : array();
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

    /** Capability needed to compose and send. Filter: fediverse_posting_capability. */
    public static function compose_capability() {
        return apply_filters( 'fediverse_posting_capability', 'publish_posts' );
    }

    /** Capability for the Settings page. Filter: fediverse_posting_settings_capability. */
    public static function settings_capability() {
        return apply_filters( 'fediverse_posting_settings_capability', 'manage_options' );
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
        Fediverse_Queue::activate();
    }

    public static function deactivate() {
        Fediverse_Queue::deactivate();
    }
}

register_activation_hook( __FILE__, array( 'Fediverse_Posting', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Fediverse_Posting', 'deactivate' ) );

$GLOBALS['fediverse_posting'] = new Fediverse_Posting();
