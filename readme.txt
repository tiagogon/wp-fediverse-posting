=== Fediverse Posting for WordPress ===
Contributors: tiagogon
Tags: mastodon, fediverse, social, autopost, schedule
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Share posts on your Mastodon (or Mastodon-compatible) account: now, when published, or from a queue with weekly time slots.

== Description ==

Connect your existing fediverse account and share posts from the editor:

* Post now, when the post is published, on the next queue slot, or at a fixed date and time.
* A Planner listing everything, with drag-to-reorder and posts that have no WordPress post behind them.
* Text + link + featured image (default), text + link, a gallery of images, or text only.
* Placeholders filled in at send time: [post-title] [post-url] [post-excerpt] [site-name], and hashtags from any taxonomy: [tags], [categories], [tax-slug].
* Alt text, content warnings, visibility and language per post, with a live preview.
* Retries for network errors, and an Idempotency-Key on every send so nothing goes out twice.

Works with Mastodon, GoToSocial, Akkoma and other servers implementing the Mastodon client API. No ActivityPub server inside WordPress, no ACF.

== Installation ==

1. Upload the plugin and activate it.
2. Fediverse → Settings: type your server and press Connect.
3. Tick the post types that get the Fediverse box, set your defaults.
4. Fediverse → Planner: add weekly time slots for the queue.

== Frequently Asked Questions ==

= Does it turn my site into a fediverse account? =

No. It posts to the account you already have. For that, look at the ActivityPub plugin.

= Why is there no link preview card? =

Mastodon does not show a card when images are attached. Without images, every server that shows the post fetches your page to build the card, so the site must be public and must not block user agents containing "Mastodon".

== Changelog ==

= 1.0.0 =
* First release.
