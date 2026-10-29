=== GRO ===
Contributors: grocerslist
Tags: email marketing, forms, memberships, content gating, affiliate
Requires at least: 4.4
Tested up to: 7.1
Stable tag: 1.31.0
Requires PHP: 7.4
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

GRO is a platform to grow your email list, send emails and sequences, gate content, sell memberships, and earn more with Amazon deep links.

== Description ==

GRO brings together the tools creators need to grow an audience and earn more from their traffic, all inside WordPress:

* **Email Marketing Suite**: build your list and send emails from one place
* **Embedded Forms**: capture subscribers anywhere on your site
* **Save to Email Forms**: let readers email themselves a post or recipe
* **Gated Content Forms**: unlock content in exchange for an email address
* **Emails & Email Sequences**: send broadcasts and automated sequences
* **Paid Memberships**: offer an ad-free or members-only experience
* **Amazon Deep Links**: open affiliate links in the Amazon app

= Email Marketing Suite =

Grow and engage your audience without stitching together separate tools. Every subscriber captured through a GRO form lands in your GRO email list, ready for broadcasts and automated sequences.

= Embedded Forms =

Add a signup form to any post, page, or sidebar with the GRO Form block or a shortcode. Forms include built-in spam protection and an optional consent checkbox.

= Save to Email Forms =

Give readers an easy way to keep your content. A save form sends the post or recipe straight to their inbox, and adds them to your list at the same time.

= Gated Content Forms =

Use the GRO Content Gate block to put part of a post behind an email signup. Readers enter their email to unlock the rest, so your best content grows your list.

= Emails & Email Sequences =

Send one-off emails to your subscribers, or set up sequences that go out automatically, such as a welcome series for new signups or follow-ups for readers who saved a post.

= Paid Memberships =

* Set a membership price for an ad-free experience on Raptive or Mediavine
* Make specific posts or pages exclusive to members
* Handle checkout and member logins through GRO, with payments processed by Stripe
* Sync paying members to email tools such as Kit, Flodesk, MailerLite, and Mailchimp

= Amazon Deep Links =

* Scan your existing posts for Amazon links
* Convert published Amazon links into deep links that open in the Amazon app, which convert 3-5X more than browser links

== Features ==

* Email marketing suite built into WordPress
* Embedded signup forms (block and shortcode)
* Save to email forms for posts and recipes
* Gated content forms with the GRO Content Gate block
* Email broadcasts and automated email sequences
* Spam protection and optional consent checkbox on all forms
* Paid memberships with ad-free and members-only content
* Member sync to Kit, Flodesk, MailerLite, and Mailchimp
* Amazon link scanning and app deep-link conversion

== Blocks ==

* **GRO Form**: embed a signup or save to email form
* **GRO Content Gate**: gate content behind an email signup

== External services ==

The plugin connects to GRO (formerly Grocers List), the service behind your GRO account, provided by GRO Holdings, Inc. For Amazon deep links, GRO forms, Digests and paid memberships it calls GRO's API at app.grocerslist.com from your site's server, with the API key you save in the plugin's settings:

* **Amazon deep links.** When a post is saved, and when the plugin scans your existing posts, it sends GRO the Amazon links in them, plus non-personal HTTP metadata, and gets back GRO short links (linksta.io) that open in the Amazon app. Your pages show those links in place of the Amazon ones, so a visitor who taps one passes through GRO's link service on the way to Amazon.
* **Posts for Digests.** GRO builds your Digest emails, roundups of your newest posts, from the posts on your site. When you publish or update a post, a page or a post of another public type that your site's search shows (not, say, a page builder's templates), the plugin sends GRO that post's public details: its title, URL, excerpt (or, without one, the description Yoast SEO or Rank Math keeps for it; for a members-only post, only an excerpt written for it, never its opening words), featured image URL, category and tag names, post type, publish and last-modified dates, whether it is for members only, and for a post with a WP Recipe Maker recipe, the recipe's courses, cuisines, keywords, total time, rating and servings. When you change a category's membership setting, or rename or delete a category or tag, it sends that category's or tag's posts again. Once an hour it sends again the posts published, edited or unpublished in the last day that GRO did not take, and once, after updating or connecting your GRO account (and again when you turn this back on or reactivate the plugin), it sends your 20 newest posts, trying again an hour later until GRO has taken them, five tries in all at most. When sending resumes (you turn this back on, reactivate the plugin, or save your GRO API key after clearing the plugin's settings), the hourly sync reaches back to when it stopped: the posts published, edited or unpublished meanwhile go too, and for each post GRO had that was deleted while this was off (the 500 most recent), its id alone, so GRO drops it. A post deleted while the plugin is deactivated is not seen, and stays in GRO. It also sends GRO the names of your categories and tags: all of them, whether or not a post has them, up to 500 of each, so you can pick them when you set up a Digest in GRO. They go when you add, rename or delete a category or tag, once an hour, and before your 20 newest posts, and are only the names, as you wrote them on your site. Only published posts without a password are sent, never a draft, a private post, the author or any visitor data; when a post is unpublished, trashed or deleted, the plugin sends GRO only the post's id so GRO drops it. You can turn this off in the plugin's settings ("Send new posts to GRO").
* **GRO form designs.** The GRO Form and GRO Content Gate blocks and the `[gro_form]` and `[gro_save_to_email]` shortcodes show forms you design in GRO. When it shows a form, and when the block editor lists your forms, the plugin fetches that form's design (its copy, layout and colors) or your list of forms from GRO. To show your GRO popup on your pages, it also fetches from GRO (`/api/v1/creator-api/popup`) whether you have a popup on and, if so, its design and settings: who it is for, the pages it shows on, when it opens and how long a closed one stays closed. These requests carry no visitor data. Whether a visitor sees the popup is decided in their browser, with two cookies the plugin's form script sets for your site and never sends to GRO: one after the visitor closes the popup or signs up through it, one when they arrive from a link in one of your GRO emails.
* **GRO form submissions.** When a visitor submits one of your GRO forms, your popup included, the plugin sends GRO the form's id, the visitor's email address, their first name if the form asks for one, whether the page showed the form's checkbox (the Save to Email opt-in, or the consent box on a signup form, content gate or popup) and whether they ticked it, and the page they were on (your home page for a popup on a page that is no single post): its URL (pointing at the WP Recipe Maker recipe card when there is one), its title or recipe name, its category names and its featured or recipe image URL. GRO then does what you set the form up to do: it adds the visitor to your contacts with the form's tags (and "Category:" tags from the page's categories), sends the email and/or adds them to the sequence you chose, and for a Save to Email form it fetches the page to build the email's preview image and emails the visitor a link to it. When a Save to Email form shows the opt-in and the visitor leaves it unticked, GRO only sends that email and does not add the visitor to your contacts. No WordPress user account is created.
* **Form updates from GRO.** When you edit a form, turn your popup on or off, or change the brand colors your forms use, GRO sends your site a notice at the plugin's `grocerslist/v1/forms/invalidate` REST route. It names the form, says whether it is your popup, and carries no visitor data. The plugin then fetches that form, or your popup, from GRO again and, if it changed, clears your page cache.
* **Advanced Tracking.** When you turn on Advanced Tracking for Raptive or Mediavine in GRO, GRO's answer to a successful GRO form submission includes the visitor's hashed email address (a SHA-256 hash, not the address itself). The plugin's form script hands it to the Raptive or Mediavine ad script already on your page, which passes it to that ad network as it does the hash on the links GRO sends, and when the form sends the visitor to another page after they sign up, that page's address carries it too, except a redirect to GRO's own links (gro.co, grocerslist.com, linksta.io and their subdomains), which goes exactly as you set it. When a form shows the opt-in checkbox and the visitor leaves it unticked, the plugin hands the ad script nothing and adds no hash to that page's address. The plugin makes no extra request for this and does not store the hash. Separately from the plugin: an ad network's identity script — the kind your ad partner loads on your pages, which this plugin does not load — can read your pages' email fields itself. On a GRO test site, observed on 2026-10-07, such a script hashed and sent the typed address as soon as the email field lost focus, with no form submission and whatever the checkbox said. The plugin does not load that script and cannot switch it off; what it collects is between you and your ad network. The plugin's own part: after a successful sign-up the visitor's browser remembers the address, and the plugin fills it into a GRO form's email field on later page views, as it does for a signed-in member.
* **Paid memberships.** When memberships are on, your pages load GRO's membership script from wp-plugin.grocerslist.com in visitors' browsers. When a visitor signs up, logs in, confirms their email or resets their password, the plugin sends GRO what they entered (their email address and password, or the code from GRO's email, with a new password for a reset) and, on sign-up, the page they were on and whether the address belongs to one of your site's WordPress users (and whether that user can edit the site); GRO keeps their member account and sends them to Stripe to pay. For signed-in members the plugin asks GRO whether their membership is active and tells GRO the id of the WordPress account the plugin signs them in with, and every hour it asks GRO which members have left so it can remove those accounts. It also tells GRO when a visitor sees a members-only gate or taps the membership bar, without any visitor data.

GRO's [Creator Terms of Service](https://gro.co/creator-tos) and [Privacy Policy](https://gro.co/privacy). More detail: [How we use external services and what data we collect](https://github.com/GrocersList/grocerslist-deep-links/blob/main/docs/EXTERNAL_SERVICES.md).

== Terms of Service and Privacy Policy ==

* [Creator Terms of Service](https://gro.co/creator-tos)
* [Privacy Policy](https://gro.co/privacy)

== Contributors & Developers ==

"GRO" is open source software. The following people have contributed to this plugin.

Contributors: GRO Holdings, Inc | Engineering

= GRO form events =

When a GRO form submission succeeds, the form fires a bubbling DOM CustomEvent: "grocerslist:subscribed" from a signup, content gate or popup form, "grocerslist:saved" from a Save to Email form. Its detail carries eventId (that submission and no other), kind ("signup", "save-to-email", "content-gate" or "popup"), status (the word the route answered with, such as "subscribed" or "sent"), placement ("block", "shortcode", "gate" or "popup"), subscribe and optInShown (booleans), and formId, postId and recipeId (strings). When the form sends the visitor to another page afterwards, that page's address carries the same eventId as "gl_eid", so a thank-you page reporting the same conversion can be told apart from a second one.

The detail never carries the visitor's email address, raw or hashed, and nothing stands in for it: anything on the page can listen, including an analytics or advertising tag, and an address reaching one is against Google Analytics 4's and Meta's terms. That is about the event; a script on the page can still read the form's email field itself, which is its own doing and not the plugin's. Nothing fires at all for a submission caught by spam protection, one your site or GRO refused, or an answer the plugin could not read.

== Changelog ==

= 1.22.1 =
* Rebrand: Grocers List is now GRO.
= 1.23.0 =
* Update Mediavine ad-removal to not remove video players.
= 1.24.0 =
* Sales page generator for admins.
= 1.25.0 =
* Security updates.
= 1.26.0 =
* WP user removal and configurable badge icon for post gating.
= 1.27.0 =
* Suppress ads for paid members on WP Recipe Maker print pages.
= 1.28.0 =
* Server side CSS for gated-post lock icon.
= 1.29.0 =
* Expand server side CSS for gated-recipe-cards with lock icon.
= 1.30.0 =
* New GRO Form block: show the signup and Save to Email forms you set up in GRO, in your brand colors. Edits you make to a form in GRO show on your site automatically, usually within seconds — for a form inside a content gate, within about five minutes.
* New `[gro_form]` and `[gro_save_to_email]` shortcodes for your GRO forms. Both also work inside WP Recipe Maker recipe card templates.
* New GRO Content Gate block: hide everything below it, or only the blocks you put inside it (a recipe card, say), until the visitor subscribes through one of your GRO forms. Visitors see a blurred preview with the form on top, and what the gate hides is not in the page until they subscribe — except that WP Recipe Maker also publishes a recipe's ingredients and steps in the page's recipe data, which the gate's new switch can leave out.
* Page-cache friendly: the plugin clears known page caches (WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed Cache, SiteGround Optimizer, WP Fastest Cache, Breeze, WP Engine, Kinsta, Cache Enabler, WP-Optimize, Nginx Helper, Hummingbird, and Cloudflare, through Cloudflare's own plugin with its cache or APO switched on) when a form changes in GRO, when the plugin is activated and when it is updated. Updating to this version clears your page cache once. A CDN or host cache the plugin cannot reach — Bunny CDN, Rocket.net's edge, Cloudflare without its plugin — keeps the old form until its own cache lifetime ends; a developer can purge it from the `grocerslist_forms_invalidated` action.
* GRO forms keep showing from the plugin's saved copy while GRO is slow or unreachable, and the copy is refreshed after the page has been sent, so your pages don't wait on GRO.
* Spam protection and rate limits for GRO forms and the content gate.
* Optional email-consent checkbox on any GRO form, ticked to start with unless the form is set in GRO to start it unticked: on a signup form or a content gate it must be ticked to sign up; on a Save to Email form it stays an optional opt-in.
* A GRO form can send visitors to a page of your choice after they sign up (never from inside a content gate, which opens in place).
* With Advanced Tracking on in GRO, a GRO form submission hands the visitor's hashed email to your site's Raptive or Mediavine ad script, as GRO's links already do, unless the form shows the opt-in checkbox and the visitor leaves it unticked.
* GRO form fixes: filling in your email with browser autofill or paste is no longer refused as too quick; a blank or mistyped address is caught on the page before anything is sent; and when a form is rate-limited, the message now says about how long to wait.
* Only real sign-ups count: a form submission caught by spam protection, or an answer your site could not read (a firewall or a maintenance page answering for the plugin), no longer counts as a sign-up. The hidden anti-spam field now hides itself even where a performance plugin strips the form's stylesheet, and the sign-up event a GRO form fires for your analytics no longer carries the visitor's email address.
* New switch on the GRO Content Gate, off to start with: leave a gated recipe's ingredients and steps out of the page's recipe data while the gate is locked. The recipe keeps its name, picture, summary, rating, times and nutrition facts, so it can still show as a recipe in search results. Clear your page cache and CDN for the post after turning it on.
* Faster for returning subscribers: what a content gate reveals is now rendered once and reused for a few minutes instead of being rebuilt for every visit. Editing the post, or a WP Recipe Maker recipe inside the gate, shows up straight away.
* Requires PHP 7.4 or newer. Tested up to WordPress 7.1.
= 1.31.0 =
* New GRO Popup: turn on a Popup form in GRO and it shows on your site's pages, with nothing to add in WordPress.
* Posts for GRO Digests: the plugin sends your published posts, categories and tags to GRO so it can build Digest emails from your newest posts. Turn it off with "Send new posts to GRO" in the plugin's settings.
