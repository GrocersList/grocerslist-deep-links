=== GRO ===
Contributors: grocerslist
Requires at least: 4.4
Author: GRO Holdings, Inc
Tested up to: 7.1
Stable tag: 1.31.0
Requires PHP: 7.4
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0

GRO is a platform to grow your email list, send emails and sequences, gate content, sell memberships, and earn more with Amazon deep links.

== Description ==

[GRO](https://gro.co) brings together the tools creators need to grow an audience and earn more from their traffic, all inside WordPress:

- **Email Marketing Suite**: build your list and send emails from one place
- **Embedded Forms**: capture subscribers anywhere on your site
- **Save to Email Forms**: let readers email themselves a post or recipe
- **Gated Content Forms**: unlock content in exchange for an email address
- **Emails & Email Sequences**: send broadcasts and automated sequences
- **Paid Memberships**: offer an ad-free or members-only experience
- **Amazon Deep Links**: open affiliate links in the Amazon app

## Email Marketing Suite

Grow and engage your audience without stitching together separate tools. Every subscriber captured through a GRO form lands in your GRO email list, ready for broadcasts and automated sequences.

## Embedded Forms

Add a signup form to any post, page, or sidebar with the GRO Form block or a shortcode. Forms include built-in spam protection and an optional consent checkbox.

## Save to Email Forms

Give readers an easy way to keep your content. A save form sends the post or recipe straight to their inbox, and adds them to your list at the same time.

## Gated Content Forms

Use the GRO Content Gate block to put part of a post behind an email signup. Readers enter their email to unlock the rest, so your best content grows your list.

## Emails & Email Sequences

Send one-off emails to your subscribers, or set up sequences that go out automatically, such as a welcome series for new signups or follow-ups for readers who saved a post.

## Paid Memberships

- Set a membership price for an ad-free experience on Raptive or Mediavine
- Make specific posts or pages exclusive to members
- Handle checkout and member logins through GRO, with payments processed by Stripe
- Sync paying members to email tools such as Kit, Flodesk, MailerLite, and Mailchimp

## Amazon Deep Links

- Scan your existing posts for Amazon links
- Convert published Amazon links into deep links that open in the Amazon app, which convert 3-5X more than browser links

<hr>

== Features ==

- Email marketing suite built into WordPress
- Embedded signup forms (block and shortcode)
- Save to email forms for posts and recipes
- Gated content forms with the GRO Content Gate block
- Email broadcasts and automated email sequences
- Spam protection and optional consent checkbox on all forms
- Paid memberships with ad-free and members-only content
- Member sync to Kit, Flodesk, MailerLite, and Mailchimp
- Amazon link scanning and app deep-link conversion

== Blocks ==

- **GRO Form**: embed a signup or save to email form
- **GRO Content Gate**: gate content behind an email signup

== Changelog ==

#### - 7/4/2025 - v1.0.0 - initial release, deeplinking

#### - 8/4/2025 - v1.0.3 - fixed a bug where bulk app links migration changes the Post's updated at field unintentionally

#### - 8/14/2025 - v1.0.6 - fixed a bug which broke third-party advertising plugins

#### - 9/02/2025 - v1.1.0 - introduced memberships feature

#### - 09/19/2025 1.2.0-beta.1 - Membership beta testing features (FOR EARLY TESTERS ONLY)

#### - 09/25/2025 - 1.2.0-beta.2 - Caching optimizations (FOR EARLY TESTERS ONLY)

#### - 9/30/2025 1.2.0 - Grocers list memberships public release.

#### – 10/14/2025 1.3.0 – WordPress Deep Links Billing fix, various fixes for memberships product, and caching optimizations

#### - 10/14/2025 - 1.4.0 - Listing page update

#### - 10/15/2025 - 1.5.0 - API updates to inform GL API of the blog URL

#### - 10/16/2025 - 1.6.0 - JS tag loading optimizations

#### - 10/16/2025 - 1.7.0 - Fix for bundle load order bug

#### - 10/21/2025 - 1.8.0 - Add noopener/noreferrer to external links, dependency updates

#### - 10/28/2025 - 1.9.0 - Feature: ability to gate posts by category, ability to apply gating defaults to categories that get inherited by posts

#### - 11/5/2025 - 1.10.0 - Ad removal optimizations for paying customers.

#### - 11/5/2025 - 1.11.0 - Remove no cache headers from plugin network requests.

#### - 11/24/2025 - 1.12.0 - Update admin and client builds to prevent dependency conflicts.

#### - 11/26/2025 - 1.13.0 - Reverting changes introduced in v.1.10.0

#### - 12/4/2025 - 1.14.0 - Ability to gate category pages and pages in wordpress admin. TopBar/Drawer can now use theme fonts.

#### - 12/4/2025 - 1.15.0 - TopBar/Drawer can now use theme fonts.

#### - 12/15/2025 - 1.16.0 - Add body classes for ad removal

#### - 01/29/2026 - 1.17.0 - Fix issue with PHP version 7.4

#### - 02/11/2026 - 1.18.0 - Security and caching optimizations

#### - 03/12/2026 - 1.19.0 - Leverage WP user records

#### - 03/13/2026 - 1.20.0 - Raptive server side ad removal and PHP 7.4 issue

#### - 03/16/2026 - 1.21.0 - Fix for body classes not being merged properly

#### - 03/17/2026 - 1.22.0 - Improvements for signup and checkout experiences

#### - 05/14/2026 - 1.22.1 - Rename plugin from Grocers List to GRO

#### - 05/25/2026 - 1.23.0 - Update Mediavine ad-removal to not remove video players

#### - 06/03/2026 - 1.24.0 - Sales page generator for admins

#### - 07/07/2026 - 1.25.0 - Security updates

#### - 07/19/2026 - 1.26.0 - Remove WP users after churn and configurable badge icon for post gating

#### - 07/28/2026 - 1.27.0 - Suppress ads for paid members on WP Recipe Maker print pages

#### - 08/10/2026 - 1.28.0 - Server side CSS for gated-post lock icon

#### - 09/10/2026 - 1.29.0 - Expand server side CSS for gated-recipe-cards with lock icon

#### - 10/08/2026 - 1.30.0 - GRO Forms and the GRO Content Gate

- New GRO Form block: show the signup and Save to Email forms you set up in GRO, in your brand colors. Edits you make to a form in GRO show on your site automatically, usually within seconds — for a form inside a content gate, within about five minutes.
- New `[gro_form]` and `[gro_save_to_email]` shortcodes for your GRO forms. Both also work inside WP Recipe Maker recipe card templates.
- New GRO Content Gate block: hide everything below it, or only the blocks you put inside it (a recipe card, say), until the visitor subscribes through one of your GRO forms. Visitors see a blurred preview with the form on top, and what the gate hides is not in the page until they subscribe — except that WP Recipe Maker also publishes a recipe's ingredients and steps in the page's recipe data, which the gate's new switch can leave out.
- Page-cache friendly: the plugin clears known page caches (WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed Cache, SiteGround Optimizer, WP Fastest Cache, Breeze, WP Engine, Kinsta, Cache Enabler, WP-Optimize, Nginx Helper, Hummingbird, and Cloudflare, through Cloudflare's own plugin with its cache or APO switched on) when a form changes in GRO, when the plugin is activated and when it is updated. Updating to this version clears your page cache once. A CDN or host cache the plugin cannot reach — Bunny CDN, Rocket.net's edge, Cloudflare without its plugin — keeps the old form until its own cache lifetime ends; a developer can purge it from the `grocerslist_forms_invalidated` action.
- GRO forms keep showing from the plugin's saved copy while GRO is slow or unreachable, and the copy is refreshed after the page has been sent, so your pages don't wait on GRO.
- Spam protection and rate limits for GRO forms and the content gate.
- Optional email-consent checkbox on any GRO form, ticked to start with unless the form is set in GRO to start it unticked: on a signup form or a content gate it must be ticked to sign up; on a Save to Email form it stays an optional opt-in.
- A GRO form can send visitors to a page of your choice after they sign up (never from inside a content gate, which opens in place).
- With Advanced Tracking on in GRO, a GRO form submission hands the visitor's hashed email to your site's Raptive or Mediavine ad script, as GRO's links already do, unless the form shows the opt-in checkbox and the visitor leaves it unticked.
- GRO form fixes: filling in your email with browser autofill or paste is no longer refused as too quick; a blank or mistyped address is caught on the page before anything is sent; and when a form is rate-limited, the message now says about how long to wait.
- Only real sign-ups count: a form submission caught by spam protection, or an answer your site could not read (a firewall or a maintenance page answering for the plugin), no longer counts as a sign-up. The hidden anti-spam field now hides itself even where a performance plugin strips the form's stylesheet, and the sign-up event a GRO form fires for your analytics no longer carries the visitor's email address.
- New switch on the GRO Content Gate, off to start with: leave a gated recipe's ingredients and steps out of the page's recipe data while the gate is locked. The recipe keeps its name, picture, summary, rating, times and nutrition facts, so it can still show as a recipe in search results. Clear your page cache and CDN for the post after turning it on.
- Faster for returning subscribers: what a content gate reveals is now rendered once and reused for a few minutes instead of being rebuilt for every visit. Editing the post, or a WP Recipe Maker recipe inside the gate, shows up straight away.
- Requires PHP 7.4 or newer. Tested up to WordPress 7.1.

#### - 10/29/2026 - 1.31.0 - GRO Popup and Posts for GRO Digests

- New GRO Popup: turn on a Popup form in GRO and it shows on your site's pages, with nothing to add in WordPress.
- Posts for GRO Digests: the plugin sends your published posts, categories and tags to GRO so it can build Digest emails from your newest posts. Turn it off with "Send new posts to GRO" in the plugin's settings.

### Resources:

- [How we use external services and what data we collect](https://github.com/GrocersList/grocerslist-deep-links/blob/main/docs/EXTERNAL_SERVICES.md)

<hr>

== Terms of Service and Privacy Policy ==

- [Creator Terms of Service](https://gro.co/creator-tos)
- [Privacy Policy](https://gro.co/privacy)
