# Fediverse Posting for WordPress

Share your WordPress posts on **Mastodon** (or any server that speaks the Mastodon API: GoToSocial, Akkoma, Pleroma) and on **Bluesky**. No ActivityPub server inside WordPress, no ACF, no theme dependency: the plugin posts to the accounts you already have.

- **Post now**, **when the post is published** (including scheduled posts), on the **next queue slot** of a weekly schedule, or at a **fixed date and time**.
- **One post, several networks.** Each post picks its destinations (pre-ticked from Settings). Every network keeps its own result: a post can be published on Mastodon and still failing on Bluesky, with its own Retry and Delete. A row is **Failed** while any of its networks failed.
- **A Planner** that lists everything, lets you drag queued posts into a new order, and writes posts that have no WordPress post behind them. Each row shows the text exactly as it will be sent, thumbnails of the attached images (flagged when they lack alt text) and a link to view the post, or its preview when it is not public.
- **Content types**: text + link + featured image (default), text + link, text + link + gallery, text only.
- **Templates with placeholders**, filled in when the post goes out: `[post-title] [post-url] [post-excerpt] [site-name]`, plus hashtags from any taxonomy: `[tags]`, `[categories]`, `[tax-<slug>]`.
  - Modifiers pick which terms: `:deepest` (most specific level of a hierarchical taxonomy), `:top` (top level), `:max=N`, `:only=` / `:not=` with term names or slugs (`[tax-medium:not=iPhone,Screenshot]`, `[tax-medium:only=iPhone]`; applied first). They combine in any order: `[tax-medium:deepest:not=iPhone:max=1]`. An unrecognised modifier is flagged in the preview.
  - A hashtag never appears twice in one post, including ones typed literally (`#Film`). Terms listed under **Never use as hashtags** are left out.
  - Each post type can have its **own default text**; empty means the general default.
- **Alt text** from the Media Library, editable per post, with a warning when an image has none.
- **Content warnings** and visibility (Mastodon), a content label for sensitive images (Bluesky), and language per post.
- **Bluesky's 300 characters**: Bluesky gets the same text, shortened at a word boundary (the link is kept) when it is longer; the preview shows exactly what goes out. Each post type can have its own, shorter Bluesky text, and each post can override it.
- **Photos and link cards on Bluesky**: posts with images go out as real images, with the link clickable in the text. Posts without images get a link card built by the plugin (title, excerpt), so it needs no public fetch. URLs and `#Hashtags` are sent as rich-text facets.
- **A live preview** per network, with each one's own counter and limits.
- **No double posts**: each row is claimed atomically; Mastodon sends carry an `Idempotency-Key`, Bluesky re-sends check the account for an identical recent post.

## Requirements

WordPress 6.4+, PHP 7.4+. The site must be able to make outgoing HTTPS requests. For Mastodon link-preview cards the site must be publicly reachable. The PHP `intl` extension is recommended: without it Bluesky's character count treats emoji sequences as several characters (never fewer), so a text may be shortened a little early.

## Install

1. Copy the folder to `wp-content/plugins/wp-fediverse-posting` (or clone this repository there) and activate it.
2. Go to **Fediverse → Settings**.
   - **Mastodon**: type your server (`mastodon.social` for `@you@mastodon.social`) and press **Connect**. Approve on your server.
   - **Bluesky**: on bsky.app create an app password (Settings → Privacy and Security → App Passwords), then paste it here with your handle. Accounts on a self-hosted PDS can give its host. The app password stays on the site so the session can be renewed.
3. Tick the post types that should get the **Fediverse** box and set your defaults, including which networks new posts go to.
4. In **Fediverse → Planner**, add weekly time slots if you want to use the queue.

On sites managed in code, the connections can come from `wp-config.php` instead:

```php
define( 'FEDIVERSE_POSTING_INSTANCE', 'mastodon.social' );
define( 'FEDIVERSE_POSTING_TOKEN', '…' ); // scopes: read:accounts write:statuses write:media

define( 'FEDIVERSE_POSTING_BLUESKY_HANDLE', 'you.bsky.social' );
define( 'FEDIVERSE_POSTING_BLUESKY_APP_PASSWORD', 'xxxx-xxxx-xxxx-xxxx' );
define( 'FEDIVERSE_POSTING_BLUESKY_PDS', 'pds.example.com' ); // optional, self-hosted accounts only
```

## How it works

Every planned post is a row in `{prefix}fediverse_queue`, with one **target** per network in `{prefix}fediverse_queue_targets`. The row owns the timing (trigger, slot, pause); each target records its own outcome, attempts, retry back-off, remote id and URL.

| When | Row starts as | Goes out |
|---|---|---|
| Now | `scheduled` (now) | immediately, from the browser request |
| When published | `waiting` | as soon as the WordPress post becomes public (single cron event) |
| Next queue slot | `scheduled` (slot) | at its slot; slots follow the Planner order |
| Date & time | `scheduled` (fixed) | at that moment; queue slots flow around it |

A cron event every 5 minutes sends due rows, one per run, to every ticked network. Network errors, `429` and `5xx` are retried after 5, 15 and 30 minutes, per network. Other errors mark that target failed; the row shows **Failed** (with the networks and reasons) and the site admin gets one email. A network that is not connected does not fail anything: the row waits until it is.

If the WordPress post leaves `publish` (draft, private, trash), its rows are **Paused**. They resume when it is published again. Deleting the post deletes the rows that never went out; history is kept for 30 days.

WP-Cron only runs when the site has visitors. For an exact schedule, call `wp-cron.php` from a real server cron every 5 minutes.

## Hooks

| Hook | Type | Use |
|---|---|---|
| `fediverse_posting_post_types` | filter | Final list of post types that get the box. |
| `fediverse_posting_language` | filter | `( $lang, $post )`: language code when the row and the default are "Automatic". |
| `fediverse_posting_hashtag` | filter | `( $tag, $term, $taxonomy )`: rename a hashtag, or return `''` to skip it. |
| `fediverse_posting_fallback_image_id` | filter | `( $id, $post )`: image to use when a post has no featured image. |
| `fediverse_posting_status_params` | filter | `( $params, $row, $post )`: the JSON sent to Mastodon's `/api/v1/statuses`. |
| `fediverse_posting_bluesky_record` | filter | `( $record, $row, $post )`: the `app.bsky.feed.post` record sent to Bluesky. |
| `fediverse_posting_networks` | filter | `( $networks )`: the `Fediverse_Network` objects keyed by id (experimental). |
| `fediverse_posting_capability` | filter | Capability to compose and send (default `publish_posts`). |
| `fediverse_posting_settings_capability` | filter | Capability for Settings (default `manage_options`). |
| `fediverse_posting_email_failures` | filter | `false` to stop failure emails. |
| `fediverse_posting_languages` | filter | Language codes offered in the composer. |
| `fediverse_posting_published` | action | `( $result, $row, $post, $network )` after a post went out on one network; `$result['network']` names it too. |

## Uninstall

Nothing is deleted unless **Settings → Admin → On uninstall** is ticked. With it ticked, uninstalling revokes the Mastodon token, ends the Bluesky session and removes the tables, options, post meta and log.

## Development

- No build step: plain PHP, jQuery and CSS.
- Code layout: `wp-fediverse-posting.php` (main class), `fediverse-queue.php` (queue engine), `fediverse-queue-settings.php` (HTML renderers), `includes/trait-*.php`, `includes/networks/` (one class per network: `Fediverse_Network` contract, Mastodon, Bluesky with its session and text helpers), `assets/`.
- Contributor notes are in [`.claude/rules/instructions.md`](.claude/rules/instructions.md).
- **`main` is a release branch.** Every push to `main` is deployed automatically to the maintainer's production site by a signed GitHub webhook. Work on feature branches and open a pull request.

## License

GPLv2 or later.
