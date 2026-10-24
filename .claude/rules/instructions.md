---
description: Coding instructions for the Fediverse Posting for WordPress plugin
paths:
  - "**"
---
When a change in the codebase invalidates or contradicts information in this file or the README.md, update both files immediately to reflect the new state.

## Portability contract

This plugin is public and must work on any WordPress site: **no ACF, no theme coupling, no site-specific post-type or taxonomy slugs in code.** Site-specific behaviour goes through the filters listed in the README (a theme hooks `fediverse_posting_language`, `fediverse_posting_fallback_image_id`, …). Trait names carry the `Fediverse_` prefix because the sibling Instagram plugin declares unprefixed traits (`Settings_Page_Trait`…) and both run on the same site.

## Architecture

- `wp-fediverse-posting.php` — `Fediverse_Posting`: settings access (`get_settings()` merges `defaults()`; the option is never autoloaded because it holds the token), connection getters (wp-config constants win), log, activation. Traits:
  - `includes/trait-settings-page.php` — menu (Planner + Settings), Settings API, `sanitize_settings()` (hidden `_source_page` = `planner` | `settings`; each page only writes its own keys), notices, `enqueue_admin_assets()` + `fediverseConfig`.
  - `includes/trait-oauth.php` — Connect (`POST /api/v1/apps`, once per server + redirect URI in option `fediverse_posting_apps`), `/oauth/authorize` with a one-time `state` transient, callback at `admin-post.php?action=fediverse_oauth_callback`, token fallback, Disconnect (`/oauth/revoke`). Tokens don't expire: no refresh cron.
  - `includes/trait-mastodon-api.php` — the only HTTP code. Statuses go as JSON (so `media_ids` is a real array everywhere). Errors are `WP_Error` with data `status`, `retryable` (network, 429, 5xx), `retry_at`. `instance_limits()` caches `/api/v2/instance` for a day. `upload_media()` polls `/api/v1/media/:id` while the server answers 202.
  - `includes/trait-content.php` — placeholders, CamelCase hashtags (`hashtagify()`: multi-word only, numbers-only terms skipped), image choice (`resolve_media_ids()`), alt text, `prepare_media_file()` (original if the server accepts its type and size, else the largest generated size, else a download).
- `fediverse-queue.php` — `Fediverse_Queue`: table `{prefix}fediverse_queue` (`DB_VERSION`, upgraded on `plugins_loaded` per site), weekly slots (ported from instagram-posting-api), `send_row()`, retries, post lifecycle, cron. Traits: `includes/trait-queue-ajax.php` (every AJAX action), `includes/trait-queue-editor.php` (meta box).
- `fediverse-queue-settings.php` — every piece of HTML: connection block, chips, schedule, rows, list, box, composer.
- `assets/js/fediverse-admin.js` — one `Box` per `.fedi-box`; the composer switches `data-mode` between `post` and `standalone`.
- `assets/css/fediverse-admin.css` — colour tokens only (`--fedi-*`, with dark overrides for "Dark Mode for WP Dashboard"); never a colour literal elsewhere.

## Rules that are easy to break

- **Reserved words**: `trigger` and `sensitive` are MySQL keywords — columns are `trigger_type` and `is_sensitive`.
- **Never send twice**: a row is claimed with `UPDATE … SET status='processing' WHERE id=%d AND status='scheduled'` and only the caller that changed 1 row sends. The Idempotency-Key is `wpfp-<site hash>-<row id>` with **no** attempt number, so a retry or a stuck-row recovery within the server's hour returns the first post.
- **Not connected ≠ failed**: `send_row()` returns early without touching the row when no account is connected.
- **Templates are rendered at send time** (and for the preview), never stored rendered.
- **Text is stored as typed**: `clean_text()` (unslash, UTF-8 check, newline normalisation). Never `sanitize_textarea_field()` — it turns `<` into `&lt;`. Escape on output.
- **Rows are rendered only in PHP** (`fediverse_render_list()`), returned as `html` by every AJAX action.
- **Meta box**: block-editor compatible, everything over AJAX. Radios need a `name` to group; they get unique names the plugin never reads.
- **Queue order** is `position`; only `trigger_type = 'queue'` rows with `attempts = 0` are re-slotted by `recalculate_schedule()`. Rows in a retry back-off keep their time; fixed-time rows (`at`) block their moment.
- `get_post( 0 )` returns the global post: always `$id ? get_post( $id ) : null`.

## Deployment

`main` is a release branch: every push to `main` is deployed automatically to the maintainer's production site by a signed GitHub webhook, so never push unfinished work to `main`. Production runs on a case-sensitive Linux filesystem while local dev is macOS; use `git mv` with a temp name for case-only renames.

Server-side details live in the git-ignored `CLAUDE.local.md` at the plugin root. Never copy them into tracked files — this repo is public.

## Decision-making protocol

Never assume functional or UX requirements — ask the maintainer before implementing anything that changes what users see or what gets posted. Code structure, naming and performance can be decided autonomously.
