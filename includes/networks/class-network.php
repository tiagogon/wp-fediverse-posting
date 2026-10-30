<?php
/**
 * Fediverse_Network: what every posting destination (Mastodon, Bluesky…) must
 * provide. One object per network, built by Fediverse_Posting::networks().
 *
 * The queue never talks to a server itself: it renders the text with the
 * network's template, prepares the images with the network's limits, then
 * calls publish(). Everything a network stores (connection, cached account)
 * lives under settings()['networks'][ id ]; volatile session state may live in
 * its own option (see Fediverse_Network_Bluesky).
 *
 * Trait and class names carry the Fediverse_ prefix: the sibling Instagram
 * plugin declares unprefixed traits and both run on the same site.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class Fediverse_Network {

    /** @var Fediverse_Posting */
    protected $plugin;

    public function __construct( Fediverse_Posting $plugin ) {
        $this->plugin = $plugin;
    }

    /** 'mastodon' | 'bluesky' — the option key, the DB value and the data attribute. */
    abstract public function id();

    /** Human name: "Mastodon", "Bluesky". */
    abstract public function label();

    /** admin_post_* connect / disconnect handlers. Called once at boot. */
    public function register_hooks() {}

    /** From stored state or wp-config constants only: never an HTTP call. */
    abstract public function is_connected();

    /** True when the connection comes from wp-config.php. */
    abstract public function uses_constants();

    /** @return array { acct, display_name, avatar, url } or array() */
    abstract public function account();

    /**
     * @return array {
     *   max_characters   int
     *   max_media        int
     *   image_size_limit int   bytes
     *   image_types      string[]
     *   alt_max          int   characters of alt text
     *   url_length       int|null  null = URLs count as typed
     *   hard_limit       bool  true: over the limit fails; false: the text is shortened at send time
     * }
     */
    abstract public function limits();

    /** How this network counts the text against max_characters. */
    public function count_characters( $text ) {
        return mb_strlen( (string) $text );
    }

    /** Shorten a text that is over the limit (networks with hard_limit false). */
    public function truncate( $text, $max ) {
        return Fediverse_Bluesky_Text::truncate_words( $text, $max, array( $this, 'count_characters' ) );
    }

    /** The row's own text for this network when set, else the common text. */
    public function template_for_row( array $row ) {
        $own = isset( $row['network_templates'][ $this->id() ] ) ? (string) $row['network_templates'][ $this->id() ] : '';
        return '' !== trim( $own ) ? $own : (string) $row['template'];
    }

    /** 'visibility' | 'spoiler_text' | 'server_link_card' | 'labels'. */
    public function supports( $feature ) {
        return false;
    }

    /**
     * Send one post.
     *
     * @param array        $row     Queue row (hydrated).
     * @param WP_Post|null $post    The WordPress post, null for standalone rows.
     * @param string       $text    Rendered (and, if needed, shortened) text.
     * @param array        $files   List of { attachment_id, path, mime, temp, alt }, prepared with this network's limits.
     * @param array        $context { attempts: int } — attempts already made on this target.
     * @return array|WP_Error { id (what delete() needs), url (public URL), network }
     */
    abstract public function publish( array $row, $post, $text, array $files, array $context = array() );

    /** @return true|WP_Error — a post already gone on the server counts as deleted. */
    abstract public function delete( $remote_id );

    /** Forget the connection (and revoke it on the server, best effort). */
    public function disconnect() {}

    /** Notice code => message for the Settings page (merged into settings_notices()). */
    public function connection_notice_messages() {
        return array();
    }


    // =====================================================================
    //  HELPERS SHARED BY EVERY NETWORK
    // =====================================================================

    /** This network's stored settings, merged with its defaults. */
    public function settings() {
        return $this->plugin->network_settings( $this->id() );
    }

    public function update_settings( array $changes ) {
        Fediverse_Posting::update_network_settings( $this->id(), $changes );
    }

    public function log( $message, $level = 'INFO' ) {
        $this->plugin->log( '[' . $this->id() . '] ' . $message, $level );
    }

    protected function redirect_with_notice( $code, $type = 'error' ) {
        wp_safe_redirect( Fediverse_Posting::settings_url( array( 'fedi_notice' => $code, 'fedi_type' => $type ) ) );
        exit;
    }

    protected function require_settings_cap( $nonce_action ) {
        if ( ! current_user_can( Fediverse_Posting::settings_capability() ) ) {
            wp_die( esc_html__( 'You are not allowed to change these settings.', 'wp-fediverse-posting' ), 403 );
        }
        check_admin_referer( $nonce_action );
    }

    /** Keep an error's text for the notice shown after the redirect. */
    protected function remember_notice_detail( $message ) {
        set_transient( 'fediverse_notice_detail_' . get_current_user_id(), (string) $message, 300 );
    }
}
