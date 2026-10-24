# Fediverse Posting for WordPress

Share your WordPress posts on your Mastodon account, or any account on a server that speaks the Mastodon API (Mastodon, GoToSocial, Akkoma, Pleroma). No ActivityPub server inside WordPress, no ACF, no theme dependency: the plugin posts to the account you already have.

- **Post now**, **when the post is published** (including scheduled posts), on the **next queue slot** of a weekly schedule, or at a **fixed date and time**.
- **A Planner** that lists everything, lets you drag queued posts into a new order, and writes posts that have no WordPress post behind them. Each row shows the text exactly as it will be sent, thumbnails of the attached images (flagged when they lack alt text) and a link to view the post, or its preview when it is not public.
- **Content types**: text + link + featured image (default), text + link, text + link + gallery, text only.
- **Templates with placeholders**, filled in when the post goes out: `[post-title] [post-url] [post-excerpt] [site-name]`, plus hashtags from any taxonomy: `[tags]`, `[categories]`, `[tax-<slug>]`.
  - Modifiers pick which terms: `:deepest` (most specific level of a hierarchical taxonomy), `:top` (top level), `:max=N`, `:only=` / `:not=` with term names or slugs (`[tax-medium:not=iPhone,Screenshot]`, `[tax-medium:only=iPhone]`; applied first). They combine in any order: `[tax-medium:deepest:not=iPhone:max=1]`. An unrecognised modifier is flagged in the preview.
  - A hashtag never appears twice in one post, including ones typed literally (`#Film`). Terms listed under **Never use as hashtags** are left out.
  - Each post type can have its **own default text**; empty means the general default.
- **Alt text** from the Media Library, editable per post, with a warning when an image has none.
- **Content warnings**, visibility, and language per post.
- **A live preview** that looks like the post on the fediverse, and a character counter that uses the server's own limit.
- **No double posts**: each row is claimed atomically and sent with an `Idempotency-Key`.

## Requirements

WordPress 6.4+, PHP 7.4+. The site must be able to make outgoing HTTPS requests. For link-preview cards the site must be publicly reachable.

## Install

1. Copy the folder to `wp-content/plugins/wp-fediverse-posting` (or clone this repository there) and activate it.
2. Go to **Fediverse → Settings**, type your server (`mastodon.social` for `@you@mastodon.social`) and press **Connect**. Approve on your server.
3. Tick the post types that should get the **Fediverse** box and set your defaults.
4. In **Fediverse → Planner**, add weekly time slots if you want to use the queue.

On sites managed in code, the connection can come from `wp-config.php` instead:

```php
define( 'FEDIVERSE_POSTING_INSTANCE', 'mastodon.social' );
define( 'FEDIVERSE_POSTING_TOKEN', '…' ); // scopes: read:accounts write:statuses write:media
```

## How it works

Every fediverse post is a row in `{prefix}fediverse_queue`.

| When | Row starts as | Goes out |
|---|---|---|
| Now | `scheduled` (now) | immediately, from the browser request |
| When published | `waiting` | as soon as the WordPress post becomes public (single cron event) |
| Next queue slot | `scheduled` (slot) | at its slot; slots follow the Planner order |
| Date & time | `scheduled` (fixed) | at that moment; queue slots flow around it |

A cron event every 5 minutes sends due rows, one per run. Network errors, `429` and `5xx` are retried after 5, 15 and 30 minutes. Other errors mark the row **Failed** in the Planner and email the site admin.

If the WordPress post leaves `publish` (draft, private, trash), its rows are **Paused**. They resume when it is published again. Deleting the post deletes the rows that never went out; history is kept for 30 days.

WP-Cron only runs when the site has visitors. For an exact schedule, call `wp-cron.php` from a real server cron every 5 minutes.

## Hooks

| Hook | Type | Use |
|---|---|---|
| `fediverse_posting_post_types` | filter | Final list of post types that get the box. |
| `fediverse_posting_language` | filter | `( $lang, $post )`: language code when the row and the default are "Automatic". |
| `fediverse_posting_hashtag` | filter | `( $tag, $term, $taxonomy )`: rename a hashtag, or return `''` to skip it. |
| `fediverse_posting_fallback_image_id` | filter | `( $id, $post )`: image to use when a post has no featured image. |
| `fediverse_posting_status_params` | filter | `( $params, $row, $post )`: the JSON sent to `/api/v1/statuses`. |
| `fediverse_posting_capability` | filter | Capability to compose and send (default `publish_posts`). |
| `fediverse_posting_settings_capability` | filter | Capability for Settings (default `manage_options`). |
| `fediverse_posting_email_failures` | filter | `false` to stop failure emails. |
| `fediverse_posting_languages` | filter | Language codes offered in the composer. |
| `fediverse_posting_published` | action | `( $result, $row, $post )` after a post went out. |

## Uninstall

Nothing is deleted unless **Settings → Admin → On uninstall** is ticked. With it ticked, uninstalling revokes the access token and removes the table, options, post meta and log.

## Development

- No build step: plain PHP, jQuery and CSS.
- Code layout: `wp-fediverse-posting.php` (main class), `fediverse-queue.php` (queue engine), `fediverse-queue-settings.php` (HTML renderers), `includes/trait-*.php`, `assets/`.
- Contributor notes are in [`.claude/rules/instructions.md`](.claude/rules/instructions.md).
- **`main` is a release branch.** Every push to `main` is deployed automatically to the maintainer's production site by a signed GitHub webhook. Work on feature branches and open a pull request.

## License

GPLv2 or later.
