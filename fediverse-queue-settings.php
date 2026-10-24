<?php
/**
 * Server-side rendering for the Planner, the editor box and the composer.
 *
 * fediverse_render_box() is the one entry point for both contexts:
 *   'planner' — every row (Upcoming + History), drag-reorder of queue rows,
 *               "+ New post" opens the composer for a standalone post.
 *   'editor'  — the rows of the edited post and "Share on the fediverse".
 * The AJAX handlers return fediverse_render_list() as `html`, so rows are only
 * ever built here. The composer is static markup filled in by fediverse-admin.js.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


// =========================================================================
//  TIME HELPERS (stored UTC, shown and typed in the site timezone)
// =========================================================================

function fediverse_format_local( $utc ) {
    if ( ! $utc ) {
        return '';
    }
    $ts = strtotime( $utc . ' UTC' );
    return wp_date( 'D j M, H:i', $ts );
}

/** "2026-10-28T19:00" (site timezone) → UTC "Y-m-d H:i:s", '' when invalid. */
function fediverse_local_to_utc( $local ) {
    $dt = DateTime::createFromFormat( 'Y-m-d\TH:i', (string) $local, wp_timezone() );
    if ( ! $dt ) {
        return '';
    }
    $dt->setTimezone( new DateTimeZone( 'UTC' ) );
    return $dt->format( 'Y-m-d H:i:s' );
}

function fediverse_utc_to_local_input( $utc ) {
    $dt = new DateTime( $utc, new DateTimeZone( 'UTC' ) );
    $dt->setTimezone( wp_timezone() );
    return $dt->format( 'Y-m-d\TH:i' );
}


// =========================================================================
//  SETTINGS PIECES
// =========================================================================

/** Connection block (outside the options form: it posts to admin-post.php). */
function fediverse_render_connection( Fediverse_Posting $plugin ) {
    $account = $plugin->get_account();
    $post_to = admin_url( 'admin-post.php' );
    ?>
    <div class="fedi-card fedi-connection">
        <h2><?php esc_html_e( 'Connection', 'wp-fediverse-posting' ); ?></h2>
        <?php if ( $plugin->is_connected() && $account ) :
            $limits = $plugin->instance_limits(); ?>
            <div class="fedi-account">
                <?php if ( ! empty( $account['avatar'] ) ) : ?>
                    <img class="fedi-account__avatar" src="<?php echo esc_url( $account['avatar'] ); ?>" alt="" width="48" height="48">
                <?php endif; ?>
                <div class="fedi-account__who">
                    <strong><?php echo esc_html( $account['display_name'] ); ?></strong>
                    <a href="<?php echo esc_url( $account['url'] ); ?>" target="_blank" rel="noopener">@<?php echo esc_html( $account['acct'] ); ?></a>
                    <span class="fedi-muted">
                        <?php
                        /* translators: 1: characters, 2: images */
                        echo esc_html( sprintf( __( 'Up to %1$d characters and %2$d images per post', 'wp-fediverse-posting' ), $limits['max_characters'], $limits['max_media'] ) );
                        ?>
                    </span>
                </div>
                <span class="fedi-badge fedi-badge--published">✓ <?php esc_html_e( 'Connected', 'wp-fediverse-posting' ); ?></span>
            </div>
            <?php if ( Fediverse_Posting::uses_constants() ) : ?>
                <p class="description"><?php echo wp_kses( __( 'Set in <code>wp-config.php</code> (FEDIVERSE_POSTING_INSTANCE / FEDIVERSE_POSTING_TOKEN). Change it there.', 'wp-fediverse-posting' ), array( 'code' => array() ) ); ?></p>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url( $post_to ); ?>">
                    <input type="hidden" name="action" value="fediverse_disconnect">
                    <?php wp_nonce_field( 'fediverse_disconnect' ); ?>
                    <button type="submit" class="button"><?php esc_html_e( 'Disconnect', 'wp-fediverse-posting' ); ?></button>
                </form>
            <?php endif; ?>

        <?php elseif ( $plugin->is_connected() ) : ?>
            <p><?php esc_html_e( 'A token is set in wp-config.php but its account has not been checked yet.', 'wp-fediverse-posting' ); ?></p>

        <?php else : ?>
            <p><?php esc_html_e( 'Post to your account on any Mastodon-compatible server (Mastodon, GoToSocial, Akkoma…).', 'wp-fediverse-posting' ); ?></p>
            <form method="post" action="<?php echo esc_url( $post_to ); ?>" class="fedi-connect-form">
                <input type="hidden" name="action" value="fediverse_connect">
                <?php wp_nonce_field( 'fediverse_connect' ); ?>
                <label for="fedi-instance"><?php esc_html_e( 'Server', 'wp-fediverse-posting' ); ?></label>
                <input type="text" id="fedi-instance" name="instance" class="regular-text" placeholder="mastodon.social" value="<?php echo esc_attr( (string) $plugin->get_setting( 'instance' ) ); ?>" autocomplete="off" spellcheck="false" required>
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Connect', 'wp-fediverse-posting' ); ?></button>
                <p class="description"><?php esc_html_e( 'The part after the second @ in your handle. You will be sent to your server to approve access, then brought back here.', 'wp-fediverse-posting' ); ?></p>
            </form>

            <details class="fedi-token-fallback">
                <summary><?php esc_html_e( 'Can\'t use Connect? Paste an access token instead', 'wp-fediverse-posting' ); ?></summary>
                <ol>
                    <li><?php esc_html_e( 'On your server, open Preferences → Development → New application.', 'wp-fediverse-posting' ); ?></li>
                    <li><?php echo wp_kses( __( 'Give it a name and tick only the scopes <code>read:accounts</code>, <code>write:statuses</code> and <code>write:media</code>.', 'wp-fediverse-posting' ), array( 'code' => array() ) ); ?></li>
                    <li><?php esc_html_e( 'Save, open the application and copy "Your access token".', 'wp-fediverse-posting' ); ?></li>
                </ol>
                <form method="post" action="<?php echo esc_url( $post_to ); ?>">
                    <input type="hidden" name="action" value="fediverse_save_token">
                    <?php wp_nonce_field( 'fediverse_save_token' ); ?>
                    <p><label><?php esc_html_e( 'Server', 'wp-fediverse-posting' ); ?><br><input type="text" name="instance" class="regular-text" placeholder="mastodon.social" autocomplete="off" required></label></p>
                    <p><label><?php esc_html_e( 'Access token', 'wp-fediverse-posting' ); ?><br><input type="password" name="access_token" class="regular-text" autocomplete="off" required></label></p>
                    <button type="submit" class="button"><?php esc_html_e( 'Check and save', 'wp-fediverse-posting' ); ?></button>
                </form>
            </details>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Clickable placeholder chips under a text field. Hashtag chips follow the
 * taxonomies of the enabled post types (or of one post's type in the editor).
 */
function fediverse_render_placeholder_chips( Fediverse_Posting $plugin, $target_id, $post_types = null, $standalone = false ) {
    echo '<div class="fedi-chips" data-target="' . esc_attr( $target_id ) . '">';
    if ( ! $standalone ) {
        echo '<span class="fedi-chips__label">' . esc_html__( 'Insert:', 'wp-fediverse-posting' ) . '</span>';
        foreach ( Fediverse_Posting::post_placeholders() as $token => $label ) {
            printf( '<button type="button" class="fedi-chip" data-insert="[%1$s]" title="%2$s">[%1$s]</button>', esc_attr( $token ), esc_attr( $label ) );
        }
        echo '<span class="fedi-chips__label">' . esc_html__( 'Hashtags:', 'wp-fediverse-posting' ) . '</span>';
        foreach ( $plugin->hashtag_placeholders( $post_types ) as $token => $label ) {
            printf( '<button type="button" class="fedi-chip fedi-chip--tag" data-insert="[%1$s]" title="%2$s">[%1$s]</button>', esc_attr( $token ), esc_attr( $label ) );
        }
    } else {
        echo '<span class="fedi-chips__label">' . esc_html__( 'Insert:', 'wp-fediverse-posting' ) . '</span>';
        echo '<button type="button" class="fedi-chip" data-insert="[site-name]">[site-name]</button>';
    }
    echo '</div>';
    if ( ! $standalone ) {
        echo '<p class="description fedi-chips__hint">' . wp_kses(
            __( 'Choose terms by adding <code>:only=</code> or <code>:not=</code> with names, e.g. <code>[tax-medium:not=iPhone,Screenshot]</code> or <code>[tax-medium:only=iPhone]</code>. Also <code>:deepest</code>, <code>:top</code>, <code>:max=2</code>.', 'wp-fediverse-posting' ),
            array( 'code' => array() )
        ) . '</p>';
    }
}

/** Weekly slot editor (Planner, inside the options form). */
function fediverse_render_schedule() {
    $opts     = Fediverse_Posting::get_settings();
    $schedule = (array) $opts['queue_schedule'];
    $name     = Fediverse_Posting::OPTION_NAME;
    $labels   = array(
        'monday'    => __( 'Monday', 'wp-fediverse-posting' ),
        'tuesday'   => __( 'Tuesday', 'wp-fediverse-posting' ),
        'wednesday' => __( 'Wednesday', 'wp-fediverse-posting' ),
        'thursday'  => __( 'Thursday', 'wp-fediverse-posting' ),
        'friday'    => __( 'Friday', 'wp-fediverse-posting' ),
        'saturday'  => __( 'Saturday', 'wp-fediverse-posting' ),
        'sunday'    => __( 'Sunday', 'wp-fediverse-posting' ),
    );
    ?>
    <table class="widefat striped fedi-schedule" data-option="<?php echo esc_attr( $name ); ?>">
        <tbody>
        <?php foreach ( Fediverse_Queue::DAYS as $day ) :
            $times = isset( $schedule[ $day ] ) ? (array) $schedule[ $day ] : array();
            sort( $times ); ?>
            <tr data-day="<?php echo esc_attr( $day ); ?>">
                <th scope="row"><?php echo esc_html( $labels[ $day ] ); ?></th>
                <td class="fedi-schedule__slots">
                    <?php foreach ( $times as $time ) : ?>
                        <span class="fedi-slot"><input type="time" step="60" name="<?php echo esc_attr( $name . '[queue_schedule][' . $day . '][]' ); ?>" value="<?php echo esc_attr( $time ); ?>"><button type="button" class="fedi-slot__remove" aria-label="<?php esc_attr_e( 'Remove time', 'wp-fediverse-posting' ); ?>">×</button></span>
                    <?php endforeach; ?>
                    <button type="button" class="button button-small fedi-slot__add"><?php esc_html_e( '+ Add', 'wp-fediverse-posting' ); ?></button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="description">
        <?php
        /* translators: %s: timezone name */
        echo esc_html( sprintf( __( 'Timezone: %s (Settings → General).', 'wp-fediverse-posting' ), wp_timezone_string() ) );
        ?>
    </p>
    <?php
}


// =========================================================================
//  ROWS
// =========================================================================

function fediverse_status_badge( $row ) {
    $map = array(
        'scheduled'  => 'queue' === $row['trigger_type'] ? __( 'In queue', 'wp-fediverse-posting' ) : ( 'at' === $row['trigger_type'] ? __( 'Fixed time', 'wp-fediverse-posting' ) : __( 'Sending soon', 'wp-fediverse-posting' ) ),
        'waiting'    => __( 'Waiting', 'wp-fediverse-posting' ),
        'processing' => __( 'Sending…', 'wp-fediverse-posting' ),
        'published'  => __( 'Published', 'wp-fediverse-posting' ),
        'failed'     => __( 'Failed', 'wp-fediverse-posting' ),
        'paused'     => __( 'Paused', 'wp-fediverse-posting' ),
        'removed'    => __( 'Deleted', 'wp-fediverse-posting' ),
    );
    $label = isset( $map[ $row['status'] ] ) ? $map[ $row['status'] ] : $row['status'];
    return '<span class="fedi-badge fedi-badge--' . esc_attr( $row['status'] ) . '">' . esc_html( $label ) . '</span>';
}

function fediverse_when_text( $row ) {
    switch ( $row['status'] ) {
        case 'published':
        case 'removed':
            return fediverse_format_local( $row['published_at'] );
        case 'waiting':
            return __( 'When published', 'wp-fediverse-posting' );
        case 'paused':
            return 'at' === $row['trigger_type'] && $row['scheduled_at'] ? fediverse_format_local( $row['scheduled_at'] ) : __( 'After the post is published', 'wp-fediverse-posting' );
    }
    return $row['scheduled_at'] ? fediverse_format_local( $row['scheduled_at'] ) : '—';
}

/**
 * Text as it will look on the fediverse: escaped, URLs shortened like
 * Mastodon shows them (30 chars, no scheme), URLs and hashtags highlighted.
 * Same rule as linkify() in fediverse-admin.js.
 */
function fediverse_linkify_text( $text ) {
    $parts = preg_split( '#(https?://\S+)#u', (string) $text, -1, PREG_SPLIT_DELIM_CAPTURE );
    $out   = '';
    foreach ( $parts as $i => $part ) {
        if ( $i % 2 ) {
            $shown = preg_replace( '#^https?://(www\.)?#', '', $part );
            if ( mb_strlen( $shown ) > 30 ) {
                $shown = mb_substr( $shown, 0, 30 ) . '…';
            }
            $out .= '<a class="fedi-link" href="' . esc_url( $part ) . '" target="_blank" rel="noopener" title="' . esc_attr( $part ) . '">' . esc_html( $shown ) . '</a>';
        } else {
            $out .= preg_replace( '/(^|\s)(#[\p{L}\p{N}_]+)/u', '$1<span class="fedi-link">$2</span>', esc_html( $part ) );
        }
    }
    return $out;
}

/** Thumbnails of the images a row will attach, with their alt text. */
function fediverse_render_row_media( Fediverse_Posting $plugin, $row, $media_ids ) {
    if ( empty( $media_ids ) ) {
        return;
    }
    echo '<div class="fedi-row__media">';
    foreach ( $media_ids as $att_id ) {
        $src = wp_get_attachment_image_src( $att_id, 'thumbnail' );
        if ( ! $src ) {
            continue;
        }
        $alt = $plugin->alt_text_for( $att_id, $row );
        printf(
            '<a class="fedi-thumb%s" href="%s" target="_blank" rel="noopener" title="%s"><img src="%s" alt="%s" width="56" height="56" loading="lazy">%s</a>',
            '' === $alt ? ' fedi-thumb--no-alt' : '',
            esc_url( wp_get_attachment_url( $att_id ) ),
            esc_attr( '' === $alt ? __( 'No alt text', 'wp-fediverse-posting' ) : $alt ),
            esc_url( $src[0] ),
            esc_attr( $alt ),
            '' === $alt ? '<span class="fedi-thumb__flag">' . esc_html__( 'no alt', 'wp-fediverse-posting' ) . '</span>' : ''
        );
    }
    echo '</div>';
}

function fediverse_render_row( Fediverse_Queue $queue, $row, $context ) {
    $plugin    = $queue->plugin;
    $post      = $row['post_id'] ? get_post( $row['post_id'] ) : null;
    $text      = $plugin->render_template( $row['template'], $post );
    $long      = mb_strlen( $text ) > 200 || substr_count( $text, "\n" ) >= 3;
    $editable  = in_array( $row['status'], array( 'scheduled', 'waiting', 'paused', 'failed' ), true );
    $sortable  = 'planner' === $context && 'queue' === $row['trigger_type'] && 'scheduled' === $row['status'] && 0 === $row['attempts'];
    $media_ids = $plugin->resolve_media_ids( $row, $post );
    $images    = count( $media_ids );
    $type      = $row['post_id'] ? Fediverse_Posting::content_type_short( $row['content_type'] ) : ( $images ? Fediverse_Posting::content_type_short( 'gallery' ) : Fediverse_Posting::content_type_short( 'text' ) );
    if ( $images > 1 ) {
        $type .= ' (' . $images . ')';
    }

    $classes = array( 'fedi-row', 'fedi-row--' . $row['status'] );
    if ( $sortable ) {
        $classes[] = 'fedi-row--sortable';
    }
    ?>
    <tr class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" data-id="<?php echo esc_attr( $row['id'] ); ?>">
        <td class="fedi-col-handle"><?php if ( $sortable ) : ?><span class="dashicons dashicons-menu fedi-handle" aria-hidden="true"></span><?php elseif ( 'at' === $row['trigger_type'] && in_array( $row['status'], array( 'scheduled', 'paused' ), true ) ) : ?><span class="dashicons dashicons-admin-post fedi-pin" title="<?php esc_attr_e( 'Fixed date and time: not moved by the queue', 'wp-fediverse-posting' ); ?>"></span><?php endif; ?></td>
        <td class="fedi-col-when"><?php echo esc_html( fediverse_when_text( $row ) ); ?></td>
        <td class="fedi-col-post">
            <div class="fedi-row__body">
                <div class="fedi-row__main">
                    <?php if ( 'planner' === $context ) : ?>
                        <div class="fedi-row__meta">
                            <?php if ( $post ) :
                                $public    = 'publish' === $post->post_status && '' === $post->post_password;
                                $view_url  = $public ? get_permalink( $post ) : get_preview_post_link( $post );
                                $type_obj  = get_post_type_object( $post->post_type ); ?>
                                <a class="fedi-row__title" href="<?php echo esc_url( $view_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_the_title( $post ) ?: __( '(no title)', 'wp-fediverse-posting' ) ); ?> <span aria-hidden="true">↗</span></a>
                                <?php if ( ! $public ) : ?><span class="fedi-muted"><?php esc_html_e( '(preview)', 'wp-fediverse-posting' ); ?></span><?php endif; ?>
                                <span class="fedi-muted">· <?php echo esc_html( $type_obj ? $type_obj->labels->singular_name : $post->post_type ); ?> ·</span>
                                <a class="fedi-muted" href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>"><?php esc_html_e( 'Edit post', 'wp-fediverse-posting' ); ?></a>
                            <?php elseif ( $row['post_id'] ) : ?>
                                <span class="fedi-muted"><?php /* translators: %d: post ID */ echo esc_html( sprintf( __( 'Post #%d (deleted)', 'wp-fediverse-posting' ), $row['post_id'] ) ); ?></span>
                            <?php else : ?>
                                <span class="fedi-muted"><?php esc_html_e( 'No WordPress post', 'wp-fediverse-posting' ); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ( $long ) : ?>
                        <details class="fedi-row__more">
                            <summary>
                                <span class="fedi-row__text"><?php echo fediverse_linkify_text( $text ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></span>
                                <span class="fedi-row__toggle fedi-row__toggle--show"><?php esc_html_e( 'Show all', 'wp-fediverse-posting' ); ?></span>
                                <span class="fedi-row__toggle fedi-row__toggle--hide"><?php esc_html_e( 'Show less', 'wp-fediverse-posting' ); ?></span>
                            </summary>
                        </details>
                    <?php else : ?>
                        <div class="fedi-row__text"><?php echo fediverse_linkify_text( $text ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></div>
                    <?php endif; ?>

                    <?php if ( '' !== $row['spoiler_text'] ) : ?>
                        <span class="fedi-row__cw"><?php /* translators: %s: content warning */ echo esc_html( sprintf( __( 'CW: %s', 'wp-fediverse-posting' ), $row['spoiler_text'] ) ); ?></span>
                    <?php endif; ?>
                    <?php if ( $row['error_message'] && in_array( $row['status'], array( 'failed', 'scheduled' ), true ) ) : ?>
                        <span class="fedi-row__error"><?php echo esc_html( $row['error_message'] ); ?></span>
                    <?php endif; ?>
                </div>
                <?php fediverse_render_row_media( $plugin, $row, $media_ids ); ?>
            </div>
        </td>
        <td class="fedi-col-type"><?php echo esc_html( $type ); ?></td>
        <td class="fedi-col-status"><?php echo fediverse_status_badge( $row ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?></td>
        <td class="fedi-col-actions">
            <?php if ( 'published' === $row['status'] && $row['remote_url'] ) : ?>
                <a href="<?php echo esc_url( $row['remote_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View ↗', 'wp-fediverse-posting' ); ?></a>
                <button type="button" class="button-link fedi-danger-link" data-fedi-action="delete_remote"><?php esc_html_e( 'Delete on fediverse', 'wp-fediverse-posting' ); ?></button>
            <?php endif; ?>
            <?php if ( in_array( $row['status'], array( 'failed', 'paused' ), true ) && ( ! $post || 'publish' === $post->post_status ) ) : ?>
                <button type="button" class="button button-small" data-fedi-action="retry"><?php echo 'failed' === $row['status'] ? esc_html__( 'Retry now', 'wp-fediverse-posting' ) : esc_html__( 'Send now', 'wp-fediverse-posting' ); ?></button>
            <?php endif; ?>
            <?php if ( $editable ) : ?>
                <button type="button" class="button-link" data-fedi-action="edit"><?php esc_html_e( 'Edit', 'wp-fediverse-posting' ); ?></button>
            <?php endif; ?>
            <?php if ( 'processing' !== $row['status'] ) : ?>
                <button type="button" class="button-link fedi-danger-link" data-fedi-action="delete" aria-label="<?php esc_attr_e( 'Remove', 'wp-fediverse-posting' ); ?>">✕</button>
            <?php endif; ?>
        </td>
    </tr>
    <?php
}

/** The rows table. Planner: Upcoming + History. Editor: this post's rows. */
function fediverse_render_list( Fediverse_Queue $queue, $context, $post_id = 0 ) {
    $rows     = $queue->get_rows( array( 'post_id' => 'editor' === $context ? (int) $post_id : null ) );
    $upcoming = array_filter( $rows, function ( $r ) {
        return ! in_array( $r['status'], array( 'published', 'removed' ), true );
    } );
    $history  = array_filter( $rows, function ( $r ) {
        return in_array( $r['status'], array( 'published', 'removed' ), true );
    } );

    ob_start();
    if ( empty( $rows ) ) {
        echo '<p class="fedi-empty">' . ( 'editor' === $context
            ? esc_html__( 'Not shared yet.', 'wp-fediverse-posting' )
            : esc_html__( 'Nothing planned yet. Share a post from its editor, or press "+ New post".', 'wp-fediverse-posting' ) ) . '</p>';
        return ob_get_clean();
    }
    ?>
    <table class="widefat fedi-table fedi-table--<?php echo esc_attr( $context ); ?>">
        <thead>
            <tr>
                <th class="fedi-col-handle"><span class="screen-reader-text"><?php esc_html_e( 'Order', 'wp-fediverse-posting' ); ?></span></th>
                <th class="fedi-col-when"><?php esc_html_e( 'When', 'wp-fediverse-posting' ); ?></th>
                <th class="fedi-col-post"><?php echo 'planner' === $context ? esc_html__( 'Post / text', 'wp-fediverse-posting' ) : esc_html__( 'Text', 'wp-fediverse-posting' ); ?></th>
                <th class="fedi-col-type"><?php esc_html_e( 'Type', 'wp-fediverse-posting' ); ?></th>
                <th class="fedi-col-status"><?php esc_html_e( 'Status', 'wp-fediverse-posting' ); ?></th>
                <th class="fedi-col-actions"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'wp-fediverse-posting' ); ?></span></th>
            </tr>
        </thead>
        <tbody class="fedi-upcoming">
            <?php foreach ( $upcoming as $row ) { fediverse_render_row( $queue, $row, $context ); } ?>
        </tbody>
        <?php if ( $history ) : ?>
            <tbody class="fedi-history">
                <?php if ( 'planner' === $context ) : ?>
                    <tr class="fedi-history__head"><th colspan="6"><?php /* translators: %d: days */ echo esc_html( sprintf( __( 'History (last %d days)', 'wp-fediverse-posting' ), Fediverse_Queue::HISTORY_DAYS ) ); ?></th></tr>
                <?php endif; ?>
                <?php foreach ( $history as $row ) { fediverse_render_row( $queue, $row, $context ); } ?>
            </tbody>
        <?php endif; ?>
    </table>
    <?php if ( 'planner' === $context && array_filter( $upcoming, function ( $r ) { return 'queue' === $r['trigger_type'] && 'scheduled' === $r['status']; } ) ) : ?>
        <p class="description fedi-legend"><?php esc_html_e( 'Drag queued posts to change their order; the time slots stay where they are. Pinned posts keep their date and time.', 'wp-fediverse-posting' ); ?></p>
    <?php endif;
    return ob_get_clean();
}


// =========================================================================
//  BOX + COMPOSER
// =========================================================================

function fediverse_render_box( Fediverse_Queue $queue, $context, $post ) {
    $plugin = $queue->plugin;

    if ( ! $plugin->is_connected() ) {
        echo '<div class="fedi-box fedi-box--off"><p>';
        if ( current_user_can( Fediverse_Posting::settings_capability() ) ) {
            printf(
                /* translators: %s: settings link */
                esc_html__( 'Not connected to a fediverse account yet. %s', 'wp-fediverse-posting' ),
                '<a href="' . esc_url( Fediverse_Posting::settings_url() ) . '">' . esc_html__( 'Connect one in Settings', 'wp-fediverse-posting' ) . '</a>'
            );
        } else {
            esc_html_e( 'Not connected to a fediverse account yet. Ask an administrator to connect one.', 'wp-fediverse-posting' );
        }
        echo '</p></div>';
        return;
    }

    $post_id   = $post ? (int) $post->ID : 0;
    $next_slot = fediverse_format_local( $queue->next_slot_preview() );
    ?>
    <div class="fedi-box fedi-box--<?php echo esc_attr( $context ); ?>" data-context="<?php echo esc_attr( $context ); ?>" data-post-id="<?php echo esc_attr( $post_id ); ?>" data-next-slot="<?php echo esc_attr( $next_slot ); ?>">
        <?php if ( 'planner' === $context ) : ?>
            <p class="fedi-summary">
                <?php
                if ( ! $queue->is_enabled() ) {
                    echo '<span class="fedi-badge fedi-badge--paused">' . esc_html__( 'Queue paused', 'wp-fediverse-posting' ) . '</span> ';
                } elseif ( ! $queue->has_schedule() ) {
                    echo '<span class="fedi-badge fedi-badge--failed">' . esc_html__( 'No time slots', 'wp-fediverse-posting' ) . '</span> ' . esc_html__( 'Add some below so queued posts can go out.', 'wp-fediverse-posting' );
                } elseif ( $next_slot ) {
                    echo esc_html__( 'Next free slot:', 'wp-fediverse-posting' ) . ' <strong class="fedi-next-slot">' . esc_html( $next_slot ) . '</strong>';
                }
                ?>
            </p>
        <?php endif; ?>

        <?php if ( 'editor' === $context ) : fediverse_render_auto_note( $queue, $post ); endif; ?>

        <div class="fedi-list"><?php echo fediverse_render_list( $queue, $context, $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?></div>

        <?php if ( 'editor' === $context ) : ?>
            <p><button type="button" class="button" data-fedi-action="compose"><?php esc_html_e( '+ Share on the fediverse', 'wp-fediverse-posting' ); ?></button></p>
        <?php endif; ?>

        <?php fediverse_render_composer( $queue, $context, $post ); ?>
        <p class="fedi-flash" role="status" aria-live="polite"></p>
    </div>
    <?php
}

/** Editor: what the "share every new post" default will do for this post. */
function fediverse_render_auto_note( Fediverse_Queue $queue, $post ) {
    if ( ! $post || ! $queue->plugin->get_setting( 'auto_on_publish' ) || 'publish' === $post->post_status ) {
        return;
    }
    if ( $queue->get_rows( array( 'post_id' => $post->ID ) ) ) {
        return;
    }
    $skip = (bool) get_post_meta( $post->ID, Fediverse_Queue::SKIP_AUTO_META, true );
    echo '<p class="fedi-auto-note">';
    if ( $skip ) {
        esc_html_e( 'This post will not be shared automatically when published.', 'wp-fediverse-posting' );
        echo ' <button type="button" class="button-link" data-fedi-action="skip_auto" data-skip="0">' . esc_html__( 'Share it after all', 'wp-fediverse-posting' ) . '</button>';
    } else {
        esc_html_e( 'Will be shared with the default settings when published.', 'wp-fediverse-posting' );
        echo ' <button type="button" class="button-link" data-fedi-action="skip_auto" data-skip="1">' . esc_html__( 'Don\'t share this one', 'wp-fediverse-posting' ) . '</button>';
    }
    echo '</p>';
}


/**
 * The composer. One per box; fediverse-admin.js switches it between
 * data-mode="post" (a WP post: content types, post placeholders, "When
 * published") and data-mode="standalone" (free text + optional images). The
 * Planner opens it in either mode: standalone for "+ New post", post mode when
 * editing a row that belongs to a WP post.
 */
function fediverse_render_composer( Fediverse_Queue $queue, $context, $post ) {
    $plugin = $queue->plugin;
    $uid    = 'fedi-' . $context . '-' . ( $post ? $post->ID : 0 );
    ?>
    <div class="fedi-composer" hidden data-mode="<?php echo $post ? 'post' : 'standalone'; ?>">
        <h3 class="fedi-composer__title">
            <span class="fedi-only-post"><?php esc_html_e( 'Share on the fediverse', 'wp-fediverse-posting' ); ?></span>
            <span class="fedi-only-standalone"><?php esc_html_e( 'New fediverse post', 'wp-fediverse-posting' ); ?></span>
        </h3>

        <div class="fedi-composer__grid">
            <div class="fedi-composer__form">
                <fieldset class="fedi-field fedi-types fedi-only-post">
                    <legend class="fedi-label"><?php esc_html_e( 'Content', 'wp-fediverse-posting' ); ?></legend>
                    <?php foreach ( Fediverse_Posting::content_types() as $value => $label ) : ?>
                        <label><input type="radio" name="<?php echo esc_attr( $uid ); ?>-type" value="<?php echo esc_attr( $value ); ?>" data-field="content_type"> <?php echo esc_html( $label ); ?></label>
                    <?php endforeach; ?>
                </fieldset>

                <div class="fedi-field">
                    <label for="<?php echo esc_attr( $uid ); ?>-text" class="fedi-label"><?php esc_html_e( 'Text', 'wp-fediverse-posting' ); ?></label>
                    <textarea id="<?php echo esc_attr( $uid ); ?>-text" rows="4" class="large-text fedi-template-input" data-field="template"></textarea>
                    <div class="fedi-counter" aria-live="polite"></div>
                    <p class="fedi-template-source description" hidden><span class="fedi-template-source__text"></span> · <button type="button" class="button-link" data-fedi-use-default></button></p>
                    <div class="fedi-only-post"><?php fediverse_render_placeholder_chips( $plugin, $uid . '-text', $post ? array( $post->post_type ) : null ); ?></div>
                    <div class="fedi-only-standalone"><?php fediverse_render_placeholder_chips( $plugin, $uid . '-text', null, true ); ?></div>
                </div>

                <div class="fedi-field fedi-images-field">
                    <span class="fedi-label"><?php esc_html_e( 'Images', 'wp-fediverse-posting' ); ?></span>
                    <div class="fedi-images"></div>
                    <button type="button" class="button fedi-pick" data-fedi-pick></button>
                    <button type="button" class="button-link fedi-reset-image" data-fedi-reset-image hidden><?php esc_html_e( 'Use the featured image', 'wp-fediverse-posting' ); ?></button>
                </div>

                <details class="fedi-field fedi-extras">
                    <summary class="fedi-extras__summary"></summary>
                    <p>
                        <label class="fedi-label" for="<?php echo esc_attr( $uid ); ?>-cw"><?php esc_html_e( 'Content warning', 'wp-fediverse-posting' ); ?></label>
                        <input type="text" id="<?php echo esc_attr( $uid ); ?>-cw" class="regular-text" maxlength="500" data-field="spoiler_text" placeholder="<?php esc_attr_e( 'Optional, e.g. flashing lights', 'wp-fediverse-posting' ); ?>">
                        <span class="description"><?php esc_html_e( 'Shown instead of the text until people choose to open it. Also blurs the images.', 'wp-fediverse-posting' ); ?></span>
                    </p>
                    <p><label><input type="checkbox" data-field="sensitive"> <?php esc_html_e( 'Mark images as sensitive', 'wp-fediverse-posting' ); ?></label></p>
                    <p>
                        <label class="fedi-label" for="<?php echo esc_attr( $uid ); ?>-vis"><?php esc_html_e( 'Visibility', 'wp-fediverse-posting' ); ?></label>
                        <select id="<?php echo esc_attr( $uid ); ?>-vis" data-field="visibility">
                            <?php foreach ( Fediverse_Posting::visibilities() as $value => $label ) : ?>
                                <option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    <p>
                        <label class="fedi-label" for="<?php echo esc_attr( $uid ); ?>-lang"><?php esc_html_e( 'Language', 'wp-fediverse-posting' ); ?></label>
                        <select id="<?php echo esc_attr( $uid ); ?>-lang" data-field="language">
                            <option value=""><?php esc_html_e( 'Automatic', 'wp-fediverse-posting' ); ?></option>
                            <?php foreach ( Fediverse_Posting::languages() as $code ) : ?>
                                <option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $code ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                </details>

                <fieldset class="fedi-field fedi-when">
                    <legend class="fedi-label"><?php esc_html_e( 'When', 'wp-fediverse-posting' ); ?></legend>
                    <div class="fedi-segmented">
                        <label><input type="radio" name="<?php echo esc_attr( $uid ); ?>-when" value="now" data-field="when"><span><?php esc_html_e( 'Now', 'wp-fediverse-posting' ); ?></span></label>
                        <label class="fedi-when--publish"><input type="radio" name="<?php echo esc_attr( $uid ); ?>-when" value="on_publish" data-field="when"><span><?php esc_html_e( 'When published', 'wp-fediverse-posting' ); ?></span></label>
                        <label><input type="radio" name="<?php echo esc_attr( $uid ); ?>-when" value="queue" data-field="when"><span><?php esc_html_e( 'Next queue slot', 'wp-fediverse-posting' ); ?></span></label>
                        <label><input type="radio" name="<?php echo esc_attr( $uid ); ?>-when" value="at" data-field="when"><span><?php esc_html_e( 'Date & time', 'wp-fediverse-posting' ); ?></span></label>
                    </div>
                    <input type="datetime-local" class="fedi-at" data-field="at" hidden>
                    <p class="fedi-when__hint description"></p>
                </fieldset>
            </div>

            <div class="fedi-composer__preview">
                <span class="fedi-label"><?php esc_html_e( 'Preview', 'wp-fediverse-posting' ); ?></span>
                <div class="fedi-preview">
                    <div class="fedi-preview__head">
                        <span class="fedi-preview__avatar"></span>
                        <span class="fedi-preview__who"><strong class="fedi-preview__name"></strong><span class="fedi-preview__acct"></span></span>
                    </div>
                    <div class="fedi-preview__cw" hidden><span class="fedi-preview__cw-text"></span> <span class="fedi-preview__cw-btn"></span></div>
                    <div class="fedi-preview__text"></div>
                    <div class="fedi-preview__media"></div>
                </div>
                <ul class="fedi-warnings"></ul>
            </div>
        </div>

        <div class="fedi-composer__actions">
            <button type="button" class="button" data-fedi-cancel><?php esc_html_e( 'Cancel', 'wp-fediverse-posting' ); ?></button>
            <button type="button" class="button button-primary" data-fedi-submit></button>
            <span class="spinner"></span>
            <span class="fedi-composer__msg" role="alert"></span>
        </div>
    </div>
    <?php
}
