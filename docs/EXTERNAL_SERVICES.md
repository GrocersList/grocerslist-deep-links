## External Services

This plugin connects to GRO (formerly Grocers List) APIs to generate deep links
for affiliate URLs, to show the creator's GRO forms, to send the site's
published posts to GRO for the creator's Digest emails, and to run the
creator's paid memberships.

- Service: GRO, formerly Grocers List (https://gro.co), provided by GRO
  Holdings, Inc. The plugin calls GRO's API at app.grocerslist.com from the
  site's server, with the site's GRO API key.
- Purpose: Convert standard affiliate links into native-app deep links, show
  the creator's GRO forms (signup, Save to Email, content gate and popup) and
  send their visitors' submissions to GRO, give GRO the site's published posts
  to build the creator's Digest emails from, and, when memberships are on, sign
  members up, log them in and check their membership with GRO.
- Data Sent for deep links: the Amazon links in a post, when it is saved and
  when the plugin scans the site's existing posts, plus non-PII HTTP metadata.
  GRO answers with short links on linksta.io that open in the Amazon app, and
  the site's pages show those in place of the Amazon links, so a visitor who
  taps one passes through GRO's link service on the way to Amazon.
- Data Sent for Digests: GRO builds the creator's Digest emails, roundups of
  their newest posts, from the site's posts. When a post, a page or a post of
  another public type that the site's search shows (not, say, a page builder's
  templates) is published or updated, the plugin sends GRO
  (`POST /api/v1/creator-api/content/posts`, with the site's GRO API key) that
  post's public details: its title, URL, excerpt (or, without one, the
  description Yoast SEO or Rank Math keeps for it; for a members-only post,
  only an excerpt written for it, never its opening words), featured image URL,
  category and tag names, post type, publish and last-modified dates, whether
  it is for members only, and for a post with a WP Recipe Maker recipe, the
  recipe's courses, cuisines, keywords, total time, rating and servings. When
  a category's membership setting changes, or a category or tag is renamed or
  deleted, it sends that category's or tag's posts again. Once an hour it
  sends again the posts published, edited or unpublished in the last day that
  GRO did not take, and once, after the plugin is updated or connected to a
  GRO account (and again when the creator turns this back on or reactivates
  the plugin), it sends the 20 newest posts, trying again an hour later until
  GRO has taken them, five tries in all at most. When sending resumes (the
  creator turns this back on, reactivates the plugin, or saves the GRO API key
  after clearing the plugin's settings), the hourly sync reaches back to when
  it stopped: the posts published, edited or unpublished meanwhile go
  too, and for each post GRO had that was deleted while sending was off (the
  500 most recent), its id alone, so GRO drops it. A post deleted while the
  plugin is deactivated is not seen, and stays in GRO. It also sends GRO the
  names of the site's categories and tags
  (`POST /api/v1/creator-api/content/terms`): all of them, whether or not a
  post has them, up to 500 of each, so the creator can pick them when setting
  up a Digest in GRO. They go when a category or tag is added, renamed or
  deleted, once an hour, and before the 20 newest posts, and are only the
  names, as the site has written them. Only published posts without a password are
  sent, never a draft, a private post, the author or any visitor data; when a
  post is unpublished, trashed or deleted, the plugin sends GRO only its id so
  GRO drops it. The creator can turn this off in the plugin's settings ("Send
  new posts to GRO").
- GRO forms are designed in GRO. When the site shows a form, and when the
  block editor lists the forms, it fetches that form's design (its copy,
  layout and colours) or the creator's list of forms from GRO with the site's
  GRO API key; these requests carry no visitor data. To show the creator's
  GRO popup on its pages, the site also fetches from GRO
  (`GET /api/v1/creator-api/popup`) whether a popup is on and, if so, its
  design and settings: who it is for, the pages it shows on, when it opens
  and for how long a closed one stays closed. That request carries no visitor
  data either; whether a visitor sees the popup is decided in their browser,
  with cookies the form script sets there for the site itself
  (`gl_popup_dismissed`, after the visitor closes it or signs up through it,
  and `gl_known`, when they arrive from a link in one of the creator's GRO
  emails), which are never sent to GRO.
- When a visitor submits a GRO form (the GRO Form or GRO Content Gate block,
  the `[gro_form]` and `[gro_save_to_email]` shortcodes, or the popup), the
  site sends
  GRO the form's id, that visitor's email address, their first name if the
  form asks for one, whether the page showed the form's checkbox (the Save to
  Email opt-in, or the consent box on a signup form or content gate) and
  whether they ticked it, and the page they were on (the site's home page for
  a popup on a page that is no single post): its URL (pointing at the
  WP Recipe Maker recipe card when there is one), its title or recipe name,
  its category names, and its featured or recipe image URL. GRO then does what
  the creator configured for that form in GRO: adds the visitor to the
  creator's contacts with the form's tags (and "Category:" tags from the
  page's categories), sends the email and/or adds them to the sequence the
  creator chose, and for a Save to Email form fetches the page to build the
  email's preview image and emails the visitor a link to it. When a Save to
  Email form shows the opt-in and the visitor leaves it unticked, GRO only
  sends that email and does not add the visitor to the creator's contacts. The
  request is made server-to-server with the site's GRO API key; no WordPress
  user account is created.
- When the creator edits a form, or changes the brand colours their forms
  use, GRO sends the site a notice that names that form and carries no
  visitor data (`POST /wp-json/grocerslist/v1/forms/invalidate`), one per
  form, saying too when the form is a popup (and so when the creator turns a
  popup on or off). The site then fetches the form, or the popup, from GRO
  again and, if it changed, clears its page cache.
- Advanced Tracking: when the creator turns on Advanced Tracking for Raptive
  or Mediavine in GRO, GRO's answer to a successful GRO form submission
  includes the visitor's hashed email address (SHA-256 of the address,
  trimmed and lowercased; never the address itself). The form script hands
  it, in the visitor's browser, to the Raptive or Mediavine ad script
  already on that page, which passes it to that ad network as it does the
  hash on GRO's own links, and when the form sends the visitor to another
  page after they sign up, that page's address carries it too (`adt_eih`
  for Raptive, `sh_mv` for Mediavine), except a redirect to GRO's own links
  (gro.co, grocerslist.com, linksta.io and their subdomains), which goes
  exactly as set. When a form shows the opt-in checkbox and the visitor
  leaves it unticked, the plugin hands the ad script nothing and adds no
  hash to that page's address. The plugin makes no request of its own for
  this and does not store the hash.
  Separately from the plugin: an ad network's identity script — the kind
  the creator's ad partner loads on their pages, which this plugin does
  not load — can read those pages' email fields itself. On a GRO test
  site, observed on 2026-10-07, such a script hashed and sent the typed
  address as soon as the email field lost focus, with no form submission
  and whatever the opt-in checkbox said. The plugin does not load that
  script and cannot switch it off; what it collects is between the creator
  and their ad network. The plugin's own part: after a successful sign-up
  the visitor's browser remembers the address, and the plugin fills it
  into a GRO form's email field on later page views, as it does for a
  signed-in member.
- Paid memberships: when memberships are on, the site's pages load GRO's
  membership script from wp-plugin.grocerslist.com in visitors' browsers.
  When a visitor signs up, logs in, confirms their email or resets their
  password, the plugin sends GRO what they entered (their email address and
  password, or the code from GRO's email, with a new password for a reset)
  and, on sign-up, the page they were on and whether the address belongs to
  one of the site's WordPress users (and whether that user can edit the
  site); GRO keeps their member account and sends them to Stripe to pay. For
  signed-in members the plugin asks GRO whether their membership is active
  and tells GRO the id of the WordPress account the plugin signs them in
  with, and every hour it asks GRO which members have left so it can remove
  those accounts. It also tells GRO when a visitor sees a members-only gate
  or taps the membership bar, without any visitor data.

Legal:

- [Creator Terms of Service](https://gro.co/creator-tos)
- [Privacy Policy](https://gro.co/privacy)

---

## Source Code

The original, unminified source code for the plugin’s JavaScript and PHP code
is available at: https://github.com/GrocersList/grocerslist-deep-links

### Runtime Dependencies

These packages are included in the distributed plugin bundle:

1. **@emotion/react**

   - Styles UI with CSS-in-JS directly in JavaScript/Preact components.

2. **@emotion/styled**

   - Provides “styled-component” syntax for reusable visual components.

3. **@mui/material**

   - Google’s Material Design UI kit for building UI components like buttons, dialogs, etc.

4. **@mui/icons-material**

   - SVG icon set matching Material UI.

5. **@mui/lab**

   - Experimental Material UI components (date pickers, timeline, etc.).

6. **preact**

   - Lightweight (~3 KB) alternative to React for smaller bundles.

7. **react-hot-toast**

   - Pop-up toast notifications for user feedback.

8. **react-spinners**
   - Loading spinners to show activity indicators.

---

### Development-Only Dependencies

These packages are used only during development and do **not** ship in the production bundle:

1. **@eslint/js** & **eslint**

   - Linting tools to catch typos and code issues before release.

2. **typescript** & **typescript-eslint**

   - TypeScript compiler and linter rules for type safety and early bug detection.

3. **@types/node**

   - Type definitions for Node.js globals.

4. **@preact/preset-vite**

   - Vite plugin to automatically swap React for Preact.

5. **vite**

   - Modern build tool for fast bundling, optimization, and hot reloads.

6. **globals**

   - Helper list for ESLint to recognize standard global variables.

7. **@wordpress/blocks**, **@wordpress/block-editor**, **@wordpress/components**, **@wordpress/element**, **@wordpress/i18n**, **@wordpress/api-fetch**

   - Type definitions for the WordPress block editor APIs used by the GRO Form
     block. The shipped block scripts call these APIs through the `wp.*`
     globals WordPress already loads, so none of these packages are bundled
     into the plugin.

8. **@types/react**
   - Type definitions the block editor's JSX types build on.

---
