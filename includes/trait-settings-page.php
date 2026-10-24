<?php
/**
 * Settings page trait: the "Fediverse" admin menu (Planner + Settings), the
 * Settings API fields, notices, assets and the admin-bar shortcut.
 *
 * Both pages post to the same option; a hidden `_source_page` tells
 * sanitize_settings() which keys belong to the submitting page, and every
 * other key is kept as stored (the connection lives outside both forms).
 *
 * Used by Fediverse_Posting.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait Fediverse_Settings_Page_Trait {

    public function add_admin_pages() {
        $cap      = self::compose_capability();
        $failed   = $this->queue->count_failed();
        $badge    = $failed ? ' <span class="awaiting-mod">' . (int) $failed . '</span>' : '';
        $position = ( 'after_media' === $this->get_setting( 'menu_position' ) ) ? 11 : 81;

        add_menu_page(
            __( 'Fediverse', 'wp-fediverse-posting' ),
            __( 'Fediverse', 'wp-fediverse-posting' ) . $badge,
            $cap,
            'fediverse-posting',
            array( $this, 'render_planner_page' ),
            'dashicons-share-alt',
            $position
        );
        add_submenu_page( 'fediverse-posting', __( 'Fediverse Planner', 'wp-fediverse-posting' ), __( 'Planner', 'wp-fediverse-posting' ), $cap, 'fediverse-posting', array( $this, 'render_planner_page' ) );
        add_submenu_page( 'fediverse-posting', __( 'Fediverse Settings', 'wp-fediverse-posting' ), __( 'Settings', 'wp-fediverse-posting' ), self::settings_capability(), 'fediverse-posting-settings', array( $this, 'render_settings_page' ) );
    }

    public function plugin_action_links( $links ) {
        array_unshift( $links, '<a href="' . esc_url( self::settings_url() ) . '">' . esc_html__( 'Settings', 'wp-fediverse-posting' ) . '</a>' );
        return $links;
    }

    public function add_admin_bar_link( $bar ) {
        if ( ! is_admin() || ! current_user_can( self::compose_capability() ) ) {
            return;
        }
        $bar->add_node( array(
            'id'    => 'fediverse-planner',
            'title' => '<span class="ab-icon dashicons dashicons-share-alt" aria-hidden="true" style="font:normal 20px/1 dashicons;padding:4px 0;"></span><span class="ab-label">' . esc_html__( 'Fediverse', 'wp-fediverse-posting' ) . '</span>',
            'href'  => admin_url( 'admin.php?page=fediverse-posting' ),
        ) );
    }


    // =====================================================================
    //  SETTINGS API
    // =====================================================================

    public function register_settings() {
        register_setting( self::OPTION_GROUP, self::OPTION_NAME, array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) ) );

        $page = 'fediverse-posting-settings';

        add_settings_section( 'fedi_post_types', __( 'Post types', 'wp-fediverse-posting' ), function () {
            echo '<p>' . esc_html__( 'The Fediverse box appears in the editor of these post types.', 'wp-fediverse-posting' ) . '</p>';
        }, $page );
        add_settings_field( 'fedi_post_types_field', __( 'Show the box on', 'wp-fediverse-posting' ), array( $this, 'field_post_types' ), $page, 'fedi_post_types' );

        add_settings_section( 'fedi_defaults', __( 'Defaults for new fediverse posts', 'wp-fediverse-posting' ), function () {
            echo '<p>' . esc_html__( 'Every new fediverse post starts from these. You can change them per post in the composer.', 'wp-fediverse-posting' ) . '</p>';
        }, $page );
        add_settings_field( 'fedi_template', __( 'Text', 'wp-fediverse-posting' ), array( $this, 'field_template' ), $page, 'fedi_defaults' );
        add_settings_field( 'fedi_exclude', __( 'Never use as hashtags', 'wp-fediverse-posting' ), array( $this, 'field_exclude' ), $page, 'fedi_defaults' );
        add_settings_field( 'fedi_type_templates', __( 'Text per post type', 'wp-fediverse-posting' ), array( $this, 'field_type_templates' ), $page, 'fedi_defaults' );
        add_settings_field( 'fedi_content_type', __( 'Content', 'wp-fediverse-posting' ), array( $this, 'field_content_type' ), $page, 'fedi_defaults' );
        add_settings_field( 'fedi_visibility', __( 'Visibility', 'wp-fediverse-posting' ), array( $this, 'field_visibility' ), $page, 'fedi_defaults' );
        add_settings_field( 'fedi_language', __( 'Language', 'wp-fediverse-posting' ), array( $this, 'field_language' ), $page, 'fedi_defaults' );
        add_settings_field( 'fedi_auto', __( 'When published', 'wp-fediverse-posting' ), array( $this, 'field_auto' ), $page, 'fedi_defaults' );

        add_settings_section( 'fedi_admin', __( 'Admin', 'wp-fediverse-posting' ), null, $page );
        add_settings_field( 'fedi_menu', __( 'Menu position', 'wp-fediverse-posting' ), array( $this, 'field_menu_position' ), $page, 'fedi_admin' );
        add_settings_field( 'fedi_debug', __( 'Debug log', 'wp-fediverse-posting' ), array( $this, 'field_debug' ), $page, 'fedi_admin' );
        add_settings_field( 'fedi_uninstall', __( 'On uninstall', 'wp-fediverse-posting' ), array( $this, 'field_uninstall' ), $page, 'fedi_admin' );

        // Planner page: queue switch + weekly slots.
        add_settings_section( 'fedi_queue', __( 'Queue', 'wp-fediverse-posting' ), function () {
            echo '<p>' . esc_html__( 'Queued posts go out one per time slot, in the order of the list above. Times use the site timezone.', 'wp-fediverse-posting' ) . '</p>';
        }, 'fediverse-posting' );
        add_settings_field( 'fedi_queue_enabled', __( 'Queue', 'wp-fediverse-posting' ), array( $this, 'field_queue_enabled' ), 'fediverse-posting', 'fedi_queue' );
        add_settings_field( 'fedi_queue_schedule', __( 'Weekly time slots', 'wp-fediverse-posting' ), 'fediverse_render_schedule', 'fediverse-posting', 'fedi_queue' );
    }

    private function field_name( $key ) {
        return esc_attr( self::OPTION_NAME . '[' . $key . ']' );
    }

    public function field_post_types() {
        $saved = (array) $this->get_setting( 'post_types' );
        $types = get_post_types( array( 'public' => true ), 'objects' );
        unset( $types['attachment'] );
        echo '<fieldset class="fedi-checklist">';
        foreach ( $types as $pt ) {
            printf(
                '<label><input type="checkbox" name="%s[]" value="%s" %s> %s <code>%s</code></label>',
                $this->field_name( 'post_types' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in field_name().
                esc_attr( $pt->name ),
                checked( in_array( $pt->name, $saved, true ), true, false ),
                esc_html( $pt->labels->name ),
                esc_html( $pt->name )
            );
        }
        echo '</fieldset>';
        echo '<p class="description">' . wp_kses( __( 'Code can still change the final list with the <code>fediverse_posting_post_types</code> filter.', 'wp-fediverse-posting' ), array( 'code' => array() ) ) . '</p>';
    }

    public function field_template() {
        printf(
            '<textarea name="%s" id="fedi-default-template" rows="3" class="large-text fedi-template-input">%s</textarea>',
            $this->field_name( 'default_template' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            esc_textarea( (string) $this->get_setting( 'default_template' ) )
        );
        fediverse_render_placeholder_chips( $this, 'fedi-default-template' );
    }

    public function field_exclude() {
        printf(
            '<input type="text" name="%s" value="%s" class="large-text" placeholder="%s">',
            $this->field_name( 'hashtag_exclude' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            esc_attr( implode( ', ', (array) $this->get_setting( 'hashtag_exclude' ) ) ),
            esc_attr__( 'e.g. Featured, Uncategorized', 'wp-fediverse-posting' )
        );
        echo '<p class="description">' . esc_html__( 'Comma-separated term names. They are left out of every hashtag line, in every post type.', 'wp-fediverse-posting' ) . '</p>';
    }

    /** One folded text box per enabled post type; empty = use the default text. */
    public function field_type_templates() {
        $types = $this->post_types();
        if ( empty( $types ) ) {
            echo '<p class="description">' . esc_html__( 'Tick some post types above first.', 'wp-fediverse-posting' ) . '</p>';
            return;
        }
        echo '<p class="description fedi-type-templates__intro">' . esc_html__( 'Optional. Leave a box empty to use the default text above.', 'wp-fediverse-posting' ) . '</p>';
        echo '<div class="fedi-type-templates">';
        foreach ( $types as $pt ) {
            $obj = get_post_type_object( $pt );
            if ( ! $obj ) {
                continue;
            }
            $own = $this->own_template_for( $pt );
            $id  = 'fedi-type-template-' . sanitize_html_class( $pt );
            ?>
            <details class="fedi-type-template" <?php echo '' !== $own ? 'data-own="1"' : ''; ?>>
                <summary>
                    <span class="fedi-type-template__name"><?php echo esc_html( $obj->labels->name ); ?></span>
                    <span class="fedi-type-template__state" data-own-label="<?php esc_attr_e( 'own text', 'wp-fediverse-posting' ); ?>" data-default-label="<?php esc_attr_e( 'uses the default', 'wp-fediverse-posting' ); ?>"><?php echo '' !== $own ? esc_html__( 'own text', 'wp-fediverse-posting' ) : esc_html__( 'uses the default', 'wp-fediverse-posting' ); ?></span>
                </summary>
                <textarea name="<?php echo esc_attr( self::OPTION_NAME . '[post_type_templates][' . $pt . ']' ); ?>" id="<?php echo esc_attr( $id ); ?>" rows="3" class="large-text fedi-template-input"><?php echo esc_textarea( $own ); ?></textarea>
                <?php fediverse_render_placeholder_chips( $this, $id, array( $pt ) ); ?>
                <p class="fedi-type-template__actions">
                    <button type="button" class="button button-small" data-fedi-copy-default="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Copy the default text', 'wp-fediverse-posting' ); ?></button>
                    <button type="button" class="button-link" data-fedi-clear-template="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Clear: use the default', 'wp-fediverse-posting' ); ?></button>
                </p>
            </details>
            <?php
        }
        echo '</div>';
    }

    public function field_content_type() {
        $current = (string) $this->get_setting( 'default_content_type' );
        echo '<fieldset class="fedi-checklist">';
        foreach ( self::content_types() as $value => $label ) {
            printf(
                '<label><input type="radio" name="%s" value="%s" %s> %s</label>',
                $this->field_name( 'default_content_type' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                esc_attr( $value ),
                checked( $current, $value, false ),
                esc_html( $label )
            );
        }
        echo '</fieldset>';
        echo '<p class="description">' . esc_html__( 'Featured image: if a post has none, its first attached image is used; with no image at all it goes out as text + link.', 'wp-fediverse-posting' ) . '</p>';
    }

    public function field_visibility() {
        $current = (string) $this->get_setting( 'default_visibility' );
        echo '<select name="' . $this->field_name( 'default_visibility' ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        foreach ( self::visibilities() as $value => $label ) {
            printf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
        }
        echo '</select>';
    }

    public function field_language() {
        $current = (string) $this->get_setting( 'default_language' );
        echo '<select name="' . $this->field_name( 'default_language' ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        /* translators: %s: language code */
        printf( '<option value="">%s</option>', esc_html( sprintf( __( 'Automatic (site language: %s)', 'wp-fediverse-posting' ), self::site_language() ) ) );
        foreach ( self::languages() as $code ) {
            printf( '<option value="%s" %s>%s</option>', esc_attr( $code ), selected( $current, $code, false ), esc_html( $code ) );
        }
        echo '</select>';
        echo '<p class="description">' . wp_kses( __( 'Helps people who filter their timeline by language. On Automatic, themes can set it per post with the <code>fediverse_posting_language</code> filter.', 'wp-fediverse-posting' ), array( 'code' => array() ) ) . '</p>';
    }

    public function field_auto() {
        printf(
            '<label><input type="checkbox" name="%s" value="1" %s> %s</label>',
            $this->field_name( 'auto_on_publish' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            checked( (bool) $this->get_setting( 'auto_on_publish' ), true, false ),
            esc_html__( 'Share every newly published post automatically, using the defaults above', 'wp-fediverse-posting' )
        );
        echo '<p class="description">' . esc_html__( 'Only posts with no fediverse post of their own yet. Each post can opt out in its Fediverse box.', 'wp-fediverse-posting' ) . '</p>';
    }

    public function field_menu_position() {
        $current = (string) $this->get_setting( 'menu_position' );
        $options = array(
            'after_media'    => __( 'After Media', 'wp-fediverse-posting' ),
            'after_settings' => __( 'After Settings', 'wp-fediverse-posting' ),
        );
        echo '<select name="' . $this->field_name( 'menu_position' ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        foreach ( $options as $value => $label ) {
            printf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
        }
        echo '</select>';
    }

    public function field_debug() {
        printf(
            '<label><input type="checkbox" name="%s" value="1" %s> %s</label>',
            $this->field_name( 'debug_logging' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            checked( (bool) $this->get_setting( 'debug_logging' ), true, false ),
            esc_html__( 'Log every request (errors are always logged)', 'wp-fediverse-posting' )
        );
    }

    public function field_uninstall() {
        printf(
            '<label><input type="checkbox" name="%s" value="1" %s> %s</label>',
            $this->field_name( 'delete_data_on_uninstall' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            checked( (bool) $this->get_setting( 'delete_data_on_uninstall' ), true, false ),
            esc_html__( 'Delete all plugin data (settings, queue, history) and revoke the access token', 'wp-fediverse-posting' )
        );
    }

    public function field_queue_enabled() {
        printf(
            '<label><input type="checkbox" name="%s" value="1" %s> %s</label>',
            $this->field_name( 'queue_enabled' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            checked( (bool) $this->get_setting( 'queue_enabled' ), true, false ),
            esc_html__( 'Send queued and scheduled posts', 'wp-fediverse-posting' )
        );
        echo '<p class="description">' . esc_html__( 'Off pauses the queue and fixed-time posts. "Now" and "When published" always go out.', 'wp-fediverse-posting' ) . '</p>';
    }

    /**
     * Keep the keys the submitting page does not own.
     *
     * register_setting() runs this on EVERY update_option() of the option,
     * not only on form posts. Internal writes (update_settings(): the OAuth
     * token, the account) carry no `_source_page` and pass through as given;
     * otherwise they would be replaced by the stored values and lost.
     */
    public function sanitize_settings( $input ) {
        $input = is_array( $input ) ? $input : array();
        if ( ! isset( $input['_source_page'] ) ) {
            return $input;
        }
        $existing = self::get_settings();
        $clean    = $existing;
        $source   = sanitize_key( $input['_source_page'] );

        if ( 'planner' === $source ) {
            $clean['queue_enabled']  = empty( $input['queue_enabled'] ) ? 0 : 1;
            $clean['queue_schedule'] = array();
            foreach ( Fediverse_Queue::DAYS as $day ) {
                $times = isset( $input['queue_schedule'][ $day ] ) ? (array) $input['queue_schedule'][ $day ] : array();
                $times = array_filter( array_map( 'sanitize_text_field', $times ), function ( $t ) {
                    return (bool) preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $t );
                } );
                $times = array_values( array_unique( $times ) );
                sort( $times );
                $clean['queue_schedule'][ $day ] = $times;
            }
            if ( $clean['queue_schedule'] !== $existing['queue_schedule'] || ( $clean['queue_enabled'] && ! $existing['queue_enabled'] ) ) {
                add_action( 'update_option_' . self::OPTION_NAME, array( $this->queue, 'recalculate_schedule' ) );
            }
        } elseif ( 'settings' === $source ) {
            $valid_types         = get_post_types( array( 'public' => true ) );
            unset( $valid_types['attachment'] );
            $clean['post_types'] = array_values( array_intersect( array_map( 'sanitize_key', isset( $input['post_types'] ) ? (array) $input['post_types'] : array() ), array_keys( $valid_types ) ) );

            $clean['default_template'] = isset( $input['default_template'] ) ? Fediverse_Queue::clean_text( $input['default_template'] ) : self::DEFAULT_TEMPLATE;

            // Boxes are only shown for ticked types: keep the stored text of the others.
            $templates = (array) $existing['post_type_templates'];
            if ( isset( $input['post_type_templates'] ) && is_array( $input['post_type_templates'] ) ) {
                foreach ( $input['post_type_templates'] as $pt => $text ) {
                    $pt   = sanitize_key( $pt );
                    $text = trim( Fediverse_Queue::clean_text( $text ) );
                    if ( '' === $text ) {
                        unset( $templates[ $pt ] );
                    } elseif ( post_type_exists( $pt ) ) {
                        $templates[ $pt ] = $text;
                    }
                }
            }
            $clean['post_type_templates'] = $templates;

            $exclude                  = isset( $input['hashtag_exclude'] ) ? explode( ',', wp_unslash( (string) $input['hashtag_exclude'] ) ) : array();
            $clean['hashtag_exclude'] = array_values( array_unique( array_filter( array_map( function ( $x ) {
                return trim( sanitize_text_field( $x ) );
            }, $exclude ) ) ) );

            $type                          = isset( $input['default_content_type'] ) ? sanitize_key( $input['default_content_type'] ) : '';
            $clean['default_content_type'] = array_key_exists( $type, self::content_types() ) ? $type : self::DEFAULT_CONTENT_TYPE;

            $vis                         = isset( $input['default_visibility'] ) ? sanitize_key( $input['default_visibility'] ) : '';
            $clean['default_visibility'] = array_key_exists( $vis, self::visibilities() ) ? $vis : 'public';

            $lang                      = isset( $input['default_language'] ) ? sanitize_key( $input['default_language'] ) : '';
            $clean['default_language'] = preg_match( '/^[a-z]{2,3}$/', $lang ) ? $lang : '';

            $clean['auto_on_publish']          = empty( $input['auto_on_publish'] ) ? 0 : 1;
            $clean['debug_logging']            = empty( $input['debug_logging'] ) ? 0 : 1;
            $clean['delete_data_on_uninstall'] = empty( $input['delete_data_on_uninstall'] ) ? 0 : 1;
            $clean['menu_position']            = ( isset( $input['menu_position'] ) && 'after_media' === $input['menu_position'] ) ? 'after_media' : 'after_settings';
        }

        return $clean;
    }


    // =====================================================================
    //  PAGES
    // =====================================================================

    public function render_settings_page() {
        if ( ! current_user_can( self::settings_capability() ) ) {
            return;
        }
        ?>
        <div class="wrap fedi-wrap">
            <h1><?php esc_html_e( 'Fediverse Settings', 'wp-fediverse-posting' ); ?></h1>

            <?php fediverse_render_connection( $this ); ?>

            <form method="post" action="options.php">
                <input type="hidden" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[_source_page]" value="settings">
                <?php
                settings_fields( self::OPTION_GROUP );
                do_settings_sections( 'fediverse-posting-settings' );
                submit_button();
                ?>
            </form>

            <hr>
            <h2><?php esc_html_e( 'Debug log', 'wp-fediverse-posting' ); ?></h2>
            <textarea readonly rows="12" class="large-text code fedi-log"><?php echo esc_textarea( $this->read_log_tail( 100 ) ); ?></textarea>
            <p><button type="button" class="button" data-fedi-clear-log data-nonce="<?php echo esc_attr( wp_create_nonce( 'fediverse_clear_log' ) ); ?>"><?php esc_html_e( 'Clear log', 'wp-fediverse-posting' ); ?></button></p>

            <hr>
            <?php include FEDIVERSE_POSTING_DIR . 'setup-guide.php'; ?>
        </div>
        <?php
    }

    public function render_planner_page() {
        if ( ! current_user_can( self::compose_capability() ) ) {
            return;
        }
        ?>
        <div class="wrap fedi-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Fediverse Planner', 'wp-fediverse-posting' ); ?></h1>
            <?php if ( $this->is_connected() ) : ?>
                <button type="button" class="page-title-action" data-fedi-action="compose"><?php esc_html_e( '+ New post', 'wp-fediverse-posting' ); ?></button>
            <?php endif; ?>
            <hr class="wp-header-end">

            <?php fediverse_render_box( $this->queue, 'planner', null ); ?>

            <?php if ( current_user_can( self::settings_capability() ) ) : ?>
                <form method="post" action="options.php" class="fedi-queue-form">
                    <input type="hidden" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[_source_page]" value="planner">
                    <?php
                    settings_fields( self::OPTION_GROUP );
                    do_settings_sections( 'fediverse-posting' );
                    submit_button( __( 'Save queue settings', 'wp-fediverse-posting' ) );
                    ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /** Result of Connect / Disconnect / token, shown on the Settings page. */
    public function settings_notices() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
        if ( empty( $_GET['page'] ) || 'fediverse-posting-settings' !== $_GET['page'] || empty( $_GET['fedi_notice'] ) ) {
            return;
        }
        $code = sanitize_key( $_GET['fedi_notice'] );
        $type = ( isset( $_GET['fedi_type'] ) && 'success' === $_GET['fedi_type'] ) ? 'success' : 'error';
        // phpcs:enable
        $messages = array(
            'connected'     => __( 'Connected. You can now share posts on the fediverse.', 'wp-fediverse-posting' ),
            'disconnected'  => __( 'Disconnected. The access token was revoked.', 'wp-fediverse-posting' ),
            'bad_instance'  => __( 'That does not look like a server name. Type it like ciberlandia.pt or mastodon.social.', 'wp-fediverse-posting' ),
            'app_failed'    => __( 'Could not register with that server. Is the name right, and does it run Mastodon or a compatible server?', 'wp-fediverse-posting' ),
            'denied'        => __( 'Access was not approved on the server.', 'wp-fediverse-posting' ),
            'bad_state'     => __( 'The connection expired or did not start here. Press Connect again.', 'wp-fediverse-posting' ),
            'token_failed'  => __( 'The server did not hand over an access token. Press Connect again.', 'wp-fediverse-posting' ),
            'verify_failed' => __( 'The server did not accept the access token.', 'wp-fediverse-posting' ),
            'no_token'      => __( 'Paste an access token first.', 'wp-fediverse-posting' ),
        );
        if ( ! isset( $messages[ $code ] ) ) {
            return;
        }
        $detail_key = 'fediverse_notice_detail_' . get_current_user_id();
        $detail     = 'error' === $type ? get_transient( $detail_key ) : '';
        delete_transient( $detail_key );

        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s%s</p></div>',
            esc_attr( $type ),
            esc_html( $messages[ $code ] ),
            $detail ? ' <code>' . esc_html( $detail ) . '</code>' : ''
        );
    }


    // =====================================================================
    //  ASSETS
    // =====================================================================

    public function maybe_enqueue_admin_assets( $hook ) {
        if ( 'toplevel_page_fediverse-posting' === $hook ) {
            $this->enqueue_admin_assets( 'planner', null );
        } elseif ( false !== strpos( $hook, 'fediverse-posting-settings' ) ) {
            $this->enqueue_admin_assets( 'settings', null );
        }
    }

    /**
     * @param string       $context planner | editor | settings
     * @param WP_Post|null $post    Edited post (editor).
     */
    public function enqueue_admin_assets( $context, $post ) {
        $css = 'assets/css/fediverse-admin.css';
        $js  = 'assets/js/fediverse-admin.js';
        wp_enqueue_style( 'fediverse-admin', FEDIVERSE_POSTING_URL . $css, array(), filemtime( FEDIVERSE_POSTING_DIR . $css ) );

        $deps = array( 'jquery' );
        if ( 'planner' === $context ) {
            $deps[] = 'jquery-ui-sortable';
        }
        wp_enqueue_script( 'fediverse-admin', FEDIVERSE_POSTING_URL . $js, $deps, filemtime( FEDIVERSE_POSTING_DIR . $js ), true );

        if ( 'settings' !== $context ) {
            wp_enqueue_media( $post ? array( 'post' => $post->ID ) : array() );
        }

        $limits  = $this->is_connected() ? $this->instance_limits() : array( 'max_characters' => 500, 'max_media' => 4 );
        $account = $this->get_account();

        wp_localize_script( 'fediverse-admin', 'fediverseConfig', array(
            'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'fediverse_queue' ),
            'context'    => $context,
            'postId'     => $post ? (int) $post->ID : 0,
            'postPublic' => $post ? ( 'publish' === $post->post_status && '' === $post->post_password ) : false,
            'maxChars'   => (int) $limits['max_characters'],
            'maxMedia'   => (int) $limits['max_media'],
            'account'    => array(
                'name'   => isset( $account['display_name'] ) ? $account['display_name'] : '',
                'acct'   => isset( $account['acct'] ) ? $account['acct'] : '',
                'avatar' => isset( $account['avatar'] ) ? $account['avatar'] : '',
            ),
            'defaults'   => array(
                'template'     => $post ? $this->default_template_for( $post->post_type ) : (string) $this->get_setting( 'default_template' ),
                'baseTemplate' => (string) $this->get_setting( 'default_template' ),
                'templateFrom' => ( $post && '' !== $this->own_template_for( $post->post_type ) && get_post_type_object( $post->post_type ) )
                    /* translators: %s: post type name */
                    ? sprintf( __( 'From the %s text in Settings', 'wp-fediverse-posting' ), get_post_type_object( $post->post_type )->labels->name )
                    : '',
                'content_type' => (string) $this->get_setting( 'default_content_type' ),
                'visibility'   => (string) $this->get_setting( 'default_visibility' ),
                'language'     => '',
            ),
            'i18n'       => array(
                'postNow'       => __( 'Post now', 'wp-fediverse-posting' ),
                'postOnPublish' => __( 'Post when published', 'wp-fediverse-posting' ),
                'addToQueue'    => __( 'Add to queue', 'wp-fediverse-posting' ),
                'schedule'      => __( 'Schedule', 'wp-fediverse-posting' ),
                'save'          => __( 'Save', 'wp-fediverse-posting' ),
                'sending'       => __( 'Sending…', 'wp-fediverse-posting' ),
                'saving'        => __( 'Saving…', 'wp-fediverse-posting' ),
                'left'          => __( 'left', 'wp-fediverse-posting' ),
                'over'          => __( 'over the limit', 'wp-fediverse-posting' ),
                'pickImage'     => __( 'Choose image', 'wp-fediverse-posting' ),
                'pickImages'    => __( 'Choose images', 'wp-fediverse-posting' ),
                'use'           => __( 'Use', 'wp-fediverse-posting' ),
                'altLabel'      => __( 'Alt text', 'wp-fediverse-posting' ),
                'altPlaceholder' => __( 'Describe the image for people who cannot see it', 'wp-fediverse-posting' ),
                'altFromLibrary' => __( 'From the Media Library. Edit here to change it for this post only.', 'wp-fediverse-posting' ),
                'remove'        => __( 'Remove', 'wp-fediverse-posting' ),
                'confirmDelete' => __( 'Remove this fediverse post?', 'wp-fediverse-posting' ),
                'confirmForget' => __( 'Remove this entry from the list? The post stays on the fediverse.', 'wp-fediverse-posting' ),
                'confirmRemote' => __( 'Delete this post on the fediverse? This cannot be undone.', 'wp-fediverse-posting' ),
                'nextSlot'      => __( 'Next slot:', 'wp-fediverse-posting' ),
                'noSlots'       => __( 'No time slots yet: add them under Fediverse → Planner.', 'wp-fediverse-posting' ),
                'nowBlocked'    => __( 'Publish the post first: its link does not work yet.', 'wp-fediverse-posting' ),
                'useDefault'    => __( 'Use the default text', 'wp-fediverse-posting' ),
                'noCw'          => __( 'No content warning', 'wp-fediverse-posting' ),
                'showMore'      => __( 'Show more', 'wp-fediverse-posting' ),
                'failed'        => __( 'Request failed. Check your connection and try again.', 'wp-fediverse-posting' ),
                'view'          => __( 'View', 'wp-fediverse-posting' ),
            ),
        ) );
    }
}
