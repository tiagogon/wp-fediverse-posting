<?php
/**
 * Uninstall: only when "Delete all plugin data" is ticked in Settings.
 * Revokes the Mastodon token, ends the Bluesky session, drops the queue
 * tables, removes options, post meta and the log folder. Otherwise everything
 * stays for a reinstall.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$fediverse_opts = get_option( 'fediverse_posting_settings', array() );
if ( empty( $fediverse_opts['delete_data_on_uninstall'] ) ) {
    return;
}

// Revoke the Mastodon token on the server (best effort). 1.0 kept the
// connection as flat keys; 1.1 under networks['mastodon'].
$fediverse_mastodon = isset( $fediverse_opts['networks']['mastodon'] ) && is_array( $fediverse_opts['networks']['mastodon'] ) ? $fediverse_opts['networks']['mastodon'] : $fediverse_opts;
$fediverse_apps     = get_option( 'fediverse_posting_apps', array() );
$fediverse_instance = isset( $fediverse_mastodon['instance'] ) ? $fediverse_mastodon['instance'] : '';
if ( $fediverse_instance && ! empty( $fediverse_mastodon['access_token'] ) && ! empty( $fediverse_apps[ $fediverse_instance ]['client_id'] ) ) {
    wp_remote_post( 'https://' . $fediverse_instance . '/oauth/revoke', array(
        'timeout' => 10,
        'body'    => array(
            'client_id'     => $fediverse_apps[ $fediverse_instance ]['client_id'],
            'client_secret' => $fediverse_apps[ $fediverse_instance ]['client_secret'],
            'token'         => $fediverse_mastodon['access_token'],
        ),
    ) );
}

// End the Bluesky session (best effort).
$fediverse_bsky = get_option( 'fediverse_posting_bluesky_session', array() );
if ( is_array( $fediverse_bsky ) && ! empty( $fediverse_bsky['refresh_jwt'] ) && ! empty( $fediverse_bsky['pds_url'] ) ) {
    wp_remote_post( untrailingslashit( $fediverse_bsky['pds_url'] ) . '/xrpc/com.atproto.server.deleteSession', array(
        'timeout' => 10,
        'headers' => array( 'Authorization' => 'Bearer ' . $fediverse_bsky['refresh_jwt'] ),
    ) );
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}fediverse_queue_targets" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}fediverse_queue" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange

delete_option( 'fediverse_posting_settings' );
delete_option( 'fediverse_posting_bluesky_session' );
delete_option( 'fediverse_posting_apps' );
delete_option( 'fediverse_queue_db_version' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_fediverse\_%' OR option_name LIKE '\_transient\_timeout\_fediverse\_%'" );
delete_post_meta_by_key( '_fediverse_skip_auto' );
delete_post_meta_by_key( '_fediverse_posted_at' );

wp_clear_scheduled_hook( 'fediverse_queue_process' );
wp_clear_scheduled_hook( 'fediverse_queue_cleanup' );
wp_unschedule_hook( 'fediverse_queue_send_row' );

$fediverse_upload = wp_upload_dir();
$fediverse_logdir = trailingslashit( $fediverse_upload['basedir'] ) . 'wp-fediverse-posting';
if ( is_dir( $fediverse_logdir ) ) {
    foreach ( (array) glob( $fediverse_logdir . '/{,.}*', GLOB_BRACE ) as $fediverse_file ) {
        if ( is_file( $fediverse_file ) ) {
            wp_delete_file( $fediverse_file );
        }
    }
    @rmdir( $fediverse_logdir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}
