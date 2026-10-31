<?php
/**
 * Setup guide shown at the bottom of Fediverse → Settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="fedi-guide">
    <h2><?php esc_html_e( 'Setup guide', 'wp-fediverse-posting' ); ?></h2>

    <h3><?php esc_html_e( '1. Connect your accounts', 'wp-fediverse-posting' ); ?></h3>
    <p><?php esc_html_e( 'Mastodon: type your server (for @you@mastodon.social it is mastodon.social) and press Connect. Approve access on your server; you come back here connected. The plugin only asks to read your profile and to write posts and images.', 'wp-fediverse-posting' ); ?></p>
    <p><?php esc_html_e( 'Bluesky: create an app password on bsky.app (Settings → Privacy and Security → App Passwords) and paste it with your handle. Bluesky sessions expire after a couple of hours; the plugin renews them with the app password, which stays on this site.', 'wp-fediverse-posting' ); ?></p>
    <p><?php echo wp_kses( __( 'On a site you manage with code, you can instead define <code>FEDIVERSE_POSTING_INSTANCE</code> and <code>FEDIVERSE_POSTING_TOKEN</code>, and <code>FEDIVERSE_POSTING_BLUESKY_HANDLE</code> and <code>FEDIVERSE_POSTING_BLUESKY_APP_PASSWORD</code>, in <code>wp-config.php</code>.', 'wp-fediverse-posting' ), array( 'code' => array() ) ); ?></p>
    <p><?php esc_html_e( 'With both connected, every post has a "Share on" choice. Each network keeps its own result: a post can be published on one and still failing on the other, with its own Retry and Delete.', 'wp-fediverse-posting' ); ?></p>

    <h3><?php esc_html_e( '2. Choose post types and defaults', 'wp-fediverse-posting' ); ?></h3>
    <p><?php esc_html_e( 'Tick the post types that should get the Fediverse box, and set the default text. Placeholders are filled in when the post goes out, so a title you fix later is not lost:', 'wp-fediverse-posting' ); ?></p>
    <ul class="ul-disc">
        <li><code>[post-title]</code>, <code>[post-url]</code>, <code>[post-excerpt]</code>, <code>[site-name]</code></li>
        <li><?php echo wp_kses( __( '<code>[tags]</code> and <code>[categories]</code> become hashtags; any custom taxonomy works as <code>[tax-slug]</code>, e.g. <code>[tax-places]</code>.', 'wp-fediverse-posting' ), array( 'code' => array() ) ); ?></li>
        <li><?php esc_html_e( 'Multi-word terms become CamelCase hashtags (#BerlinWall) so screen readers read them well. Number-only terms are skipped.', 'wp-fediverse-posting' ); ?></li>
        <li><?php echo wp_kses( __( 'For hierarchical taxonomies, <code>[tax-medium:deepest]</code> gives only the most specific terms and <code>[tax-medium:top]</code> only the top level. <code>[tax-places:max=3]</code> keeps at most three. <code>[tax-medium:not=iPhone,Screenshot]</code> leaves those terms out and <code>[tax-medium:only=iPhone]</code> keeps just that one (names or slugs, checked first). Modifiers combine.', 'wp-fediverse-posting' ), array( 'code' => array() ) ); ?></li>
        <li><?php esc_html_e( 'No hashtag appears twice in one post, counting the ones you type yourself. Terms listed under "Never use as hashtags" are always left out.', 'wp-fediverse-posting' ); ?></li>
        <li><?php esc_html_e( 'Under "Text per post type" each post type can start from its own text; leave it empty to use the default.', 'wp-fediverse-posting' ); ?></li>
        <li><?php esc_html_e( 'Bluesky posts take 300 characters. By default Bluesky gets the same text, shortened at a word boundary when it is longer (the link is kept). "Bluesky text per post type" gives it a shorter text of its own, and each post can override that in the composer.', 'wp-fediverse-posting' ); ?></li>
    </ul>

    <h3><?php esc_html_e( '3. Share', 'wp-fediverse-posting' ); ?></h3>
    <p><?php esc_html_e( 'In a post\'s Fediverse box press "Share on the fediverse" and pick when:', 'wp-fediverse-posting' ); ?></p>
    <ul class="ul-disc">
        <li><strong><?php esc_html_e( 'Now', 'wp-fediverse-posting' ); ?></strong> — <?php esc_html_e( 'right away (the post must be published so its link works).', 'wp-fediverse-posting' ); ?></li>
        <li><strong><?php esc_html_e( 'When published', 'wp-fediverse-posting' ); ?></strong> — <?php esc_html_e( 'as soon as the post goes live, including scheduled posts.', 'wp-fediverse-posting' ); ?></li>
        <li><strong><?php esc_html_e( 'Next queue slot', 'wp-fediverse-posting' ); ?></strong> — <?php esc_html_e( 'the next free time slot of the weekly schedule (Fediverse → Planner).', 'wp-fediverse-posting' ); ?></li>
        <li><strong><?php esc_html_e( 'Date & time', 'wp-fediverse-posting' ); ?></strong> — <?php esc_html_e( 'a fixed moment that the queue leaves alone.', 'wp-fediverse-posting' ); ?></li>
    </ul>
    <p><?php esc_html_e( 'The Planner lists everything, lets you drag queued posts into a new order, and its "+ New post" button writes a post that has no WordPress post behind it.', 'wp-fediverse-posting' ); ?></p>

    <h3><?php esc_html_e( '4. Make the schedule reliable', 'wp-fediverse-posting' ); ?></h3>
    <p><?php esc_html_e( 'WordPress runs scheduled tasks only when someone visits the site. On quiet sites, queued posts can go out late. Ask your host to call wp-cron.php every 5 minutes from a real server cron, and set DISABLE_WP_CRON in wp-config.php:', 'wp-fediverse-posting' ); ?></p>
    <pre><code>*/5 * * * * curl -s <?php echo esc_html( site_url( 'wp-cron.php?doing_wp_cron' ) ); ?> &gt; /dev/null</code></pre>

    <h3><?php esc_html_e( '5. Link previews', 'wp-fediverse-posting' ); ?></h3>
    <p><?php esc_html_e( 'Mastodon: for text + link posts, every server that shows the post fetches your page to build the preview card, from its Open Graph tags (an SEO plugin usually provides them). If a firewall or bot blocker on your site refuses user agents containing "Mastodon", the cards come out blank. Sites on localhost never get cards. With images attached, Mastodon shows the images instead of a card.', 'wp-fediverse-posting' ); ?></p>
    <p><?php esc_html_e( 'Bluesky: nothing is fetched. For text + link posts the plugin builds the card itself from the post title and excerpt, so it works everywhere. With images attached, Bluesky shows the images instead of a card, and the link stays clickable in the text.', 'wp-fediverse-posting' ); ?></p>

    <h3><?php esc_html_e( 'If a post fails', 'wp-fediverse-posting' ); ?></h3>
    <p><?php esc_html_e( 'Network and server errors are retried three times (after 5, 15 and 30 minutes), per network. Anything else marks the post Failed in the Planner, with the reason, and emails the site admin. Mastodon sends carry a key that stops the server from posting the same thing twice; on Bluesky a retried send first checks the account for an identical recent post.', 'wp-fediverse-posting' ); ?></p>
</div>
