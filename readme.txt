=== Fediverse Posting for WordPress ===
Contributors: tiagogon
Tags: mastodon, bluesky, fediverse, atproto, social, autopost, schedule
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Share posts on Mastodon (or any Mastodon-compatible server) and Bluesky: now, when published, or from a queue with weekly time slots.

== Description ==

Connect your existing Mastodon and Bluesky accounts and share posts from the editor:

* Post now, when the post is published, on the next queue slot, or at a fixed date and time.
* One post, several networks: pick where each post goes; every network keeps its own result, retry and delete.
* A Planner listing everything, with drag-to-reorder and posts that have no WordPress post behind them.
* Text + link + featured image (default), text + link, a gallery of images, or text only.
* Placeholders filled in at send time: [post-title] [post-url] [post-excerpt] [site-name], and hashtags from any taxonomy: [tags], [categories], [tax-slug].
* Alt text, content warnings and visibility (Mastodon), content labels (Bluesky) and language per post, with a live preview per network.
* Bluesky's 300 characters: the text is shortened at a word boundary when needed (the link is kept), or each post type gets its own shorter Bluesky text. Link cards are built by the plugin.
* Retries for network errors, and no double posts: an Idempotency-Key on Mastodon sends, a duplicate check on Bluesky re-sends.

Works with Mastodon, GoToSocial, Akkoma and other servers implementing the Mastodon client API, and with Bluesky (app password). No ActivityPub server inside WordPress, no ACF.

== Installation ==

1. Upload the plugin and activate it.
2. Fediverse → Settings: for Mastodon type your server and press Connect; for Bluesky paste an app password with your handle.
3. Tick the post types that get the Fediverse box, set your defaults.
4. Fediverse → Planner: add weekly time slots for the queue.

== Frequently Asked Questions ==

= Does it turn my site into a fediverse account? =

No. It posts to the account you already have. For that, look at the ActivityPub plugin.

= Why is there no link preview card? =

Mastodon does not show a card when images are attached. Without images, every server that shows the post fetches your page to build the card, so the site must be public and must not block user agents containing "Mastodon".

= Why does the Bluesky post have a card when the Mastodon one does not? =

Bluesky fetches nothing: the plugin builds the card itself from the title, excerpt and image, so it is always there. Galleries show as an image grid instead.

== Changelog ==

= 1.1.0 =
* Bluesky as a second destination (app password). Each post picks its networks; every network keeps its own status, retry and delete.
* Bluesky text per post type and per post; longer texts are shortened at a word boundary, keeping the link.
* Link cards, image grids, hashtag and link facets, language and content labels on Bluesky.
* Images too big for a network are re-encoded as a smaller JPEG.

= 1.0.0 =
* First release.
