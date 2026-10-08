=== GRO ===
Contributors: grocerslist
Tags: affiliate, amazon, memberships, monetization, deep links
Requires at least: 4.4
Tested up to: 7.1
Stable tag: 1.29.0
Requires PHP: 7.4
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

GRO is a suite of tools for bloggers — monetize your site with paid memberships and Amazon deep links.

== Description ==

GRO is a suite of tools built for bloggers. The plugin makes it super easy to start earning more revenue from your website traffic through our two plugin based services:

= Paid Memberships =

Offer website visitors access to an ad-free experience by joining your membership program. Offer paid members exclusive access to specific posts, pages or other gated content. Easily set your membership price, customize your membership display settings, and we will handle the paid membership checkout, member logins, and member exclusive content gating. Open up a predictable, recurring revenue stream on top of your existing content site, rather than pushing your audience into substack.

= Amazon Deep links =

Earn more from the existing Amazon affiliate links published on your posts. Connect the plugin to your existing GRO account, and we'll quickly find all amazon links published on your site.

We'll then automatically convert them into deep links for Amazon. When clicked, it will bring the visitor into their Amazon app on their phone, where they have the highest likelihood of converting. Driving clicks into the Amazon app, rather than into Amazon in the browser, converts 3-5X more.

== Features ==

An overview of our features:

* Set a price for your paid membership, which will get your audience access to an ad-free experience on Raptive or Mediavine.
* Option to set specific posts, or specific pages as member-exclusive benefits.
* Automatically sync paying members to Kit (formerly ConvertKit), Flodesk, mailer lite, mailchimp and more.
* Quickly scan all your WordPress posts to find previously published Amazon links.
* Automatically convert all published Amazon links into deep links that open in the Amazon app.

== External services ==

The plugin connects to GRO (formerly Grocers List), the service behind your GRO account, provided by GRO Holdings, Inc. For Amazon deep links, GRO forms and paid memberships it calls GRO's API at app.grocerslist.com from your site's server, with the API key you save in the plugin's settings:

* **Amazon deep links.** When a post is saved, and when the plugin scans your existing posts, it sends GRO the Amazon links in them, plus non-personal HTTP metadata, and gets back GRO short links (linksta.io) that open in the Amazon app. Your pages show those links in place of the Amazon ones, so a visitor who taps one passes through GRO's link service on the way to Amazon.
* **GRO form designs.** The GRO Form and GRO Content Gate blocks and the `[gro_form]` and `[gro_save_to_email]` shortcodes show forms you design in GRO. When it shows a form, and when the block editor lists your forms, the plugin fetches that form's design (its copy, layout and colors) or your list of forms from GRO. These requests carry no visitor data.
* **GRO form submissions.** When a visitor submits one of your GRO forms, the plugin sends GRO the form's id, the visitor's email address, their first name if the form asks for one, whether the page showed the form's checkbox (the Save to Email opt-in, or the consent box on a signup form or content gate) and whether they ticked it, and the page they were on: its URL (pointing at the WP Recipe Maker recipe card when there is one), its title or recipe name, its category names and its featured or recipe image URL. GRO then does what you set the form up to do: it adds the visitor to your contacts with the form's tags (and "Category:" tags from the page's categories), sends the email and/or adds them to the sequence you chose, and for a Save to Email form it fetches the page to build the email's preview image and emails the visitor a link to it. When a Save to Email form shows the opt-in and the visitor leaves it unticked, GRO only sends that email and does not add the visitor to your contacts. No WordPress user account is created.
* **Form updates from GRO.** When you edit a form, or change the brand colors your forms use, GRO sends your site a notice at the plugin's `grocerslist/v1/forms/invalidate` REST route. It names the form and carries no visitor data. The plugin then fetches that form from GRO again and, if it changed, clears your page cache.
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

When a GRO form submission succeeds, the form fires a bubbling DOM CustomEvent: "grocerslist:subscribed" from a signup or content gate form, "grocerslist:saved" from a Save to Email form. Its detail carries eventId (that submission and no other), kind ("signup", "save-to-email" or "content-gate"), status (the word the route answered with, such as "subscribed" or "sent"), placement ("block", "shortcode" or "gate"), subscribe and optInShown (booleans), and formId, postId and recipeId (strings). When the form sends the visitor to another page afterwards, that page's address carries the same eventId as "gl_eid", so a thank-you page reporting the same conversion can be told apart from a second one.

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
= 1.30.0-beta.1 =
* Beta: WordPress.org does not offer this version as an update. To try it, download https://downloads.wordpress.org/plugin/grocerslist.1.30.0-beta.1.zip and install it with Plugins → Add New → Upload Plugin → Replace current with uploaded.
* New GRO Form block: show the signup and Save to Email forms you set up in GRO, in your brand colors. Edits you make to a form in GRO show on your site automatically, usually within seconds.
* New `[gro_form]` and `[gro_save_to_email]` shortcodes for your GRO forms. Both also work inside WP Recipe Maker recipe card templates.
* New GRO Content Gate block: hide everything below it, or only the blocks you put inside it (a recipe card, say), until the visitor subscribes through one of your GRO forms. Visitors see a blurred preview with the form on top; what the gate hides is not in the page until they subscribe.
* Page-cache friendly: the plugin clears known page caches (WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed Cache, SiteGround Optimizer, WP Fastest Cache, Breeze, WP Engine and Kinsta) when a form changes in GRO, when the plugin is activated and when it is updated. Updating to this version clears your page cache once.
* GRO forms keep showing from the plugin's saved copy while GRO is slow or unreachable, and the copy is refreshed after the page has been sent, so your pages don't wait on GRO.
* Spam protection and rate limits for GRO forms and the content gate.
* Optional email-consent checkbox on any GRO form, ticked to start with unless the form is set in GRO to start it unticked: on a signup form or a content gate it must be ticked to sign up; on a Save to Email form it stays an optional opt-in.
* A GRO form can send visitors to a page of your choice after they sign up (never from inside a content gate, which opens in place).
* With Advanced Tracking on in GRO, a GRO form submission hands the visitor's hashed email to your site's Raptive or Mediavine ad script, as GRO's links already do, unless the form shows the opt-in checkbox and the visitor leaves it unticked.
* GRO form fixes: filling in your email with browser autofill or paste is no longer refused as too quick; a blank or mistyped address is caught on the page before anything is sent; and when a form is rate-limited, the message now says about how long to wait.
* Only real sign-ups count: a form submission caught by spam protection, or an answer your site could not read (a firewall or a maintenance page answering for the plugin), no longer counts as a sign-up. The hidden anti-spam field now hides itself even where a performance plugin strips the form's stylesheet, and the sign-up event a GRO form fires for your analytics no longer carries the visitor's email address.
* Requires PHP 7.4 or newer. Tested up to WordPress 7.1.
