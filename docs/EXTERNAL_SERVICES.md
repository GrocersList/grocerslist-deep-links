## External Services

This plugin connects to GRO (formerly Grocers List) APIs to generate deep links
for affiliate URLs, to show the creator's GRO forms, and to run the creator's
paid memberships.

- Service: GRO, formerly Grocers List (https://gro.co), provided by GRO
  Holdings, Inc. The plugin calls GRO's API at app.grocerslist.com from the
  site's server, with the site's GRO API key.
- Purpose: Convert standard affiliate links into native-app deep links, show
  the creator's GRO forms (signup, Save to Email and content gate) and send
  their visitors' submissions to GRO, and, when memberships are on, sign
  members up, log them in and check their membership with GRO.
- Data Sent for deep links: the Amazon links in a post, when it is saved and
  when the plugin scans the site's existing posts, plus non-PII HTTP metadata.
  GRO answers with short links on linksta.io that open in the Amazon app, and
  the site's pages show those in place of the Amazon links, so a visitor who
  taps one passes through GRO's link service on the way to Amazon.
- GRO forms are designed in GRO. When the site shows a form, and when the
  block editor lists the forms, it fetches that form's design (its copy,
  layout and colours) or the creator's list of forms from GRO with the site's
  GRO API key; these requests carry no visitor data.
- When a visitor submits a GRO form (the GRO Form or GRO Content Gate block,
  or the `[gro_form]` and `[gro_save_to_email]` shortcodes), the site sends
  GRO the form's id, that visitor's email address, their first name if the
  form asks for one, whether the page showed the form's checkbox (the Save to
  Email opt-in, or the consent box on a signup form or content gate) and
  whether they ticked it, and the page they were on: its URL (pointing at the
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
  form. The site then fetches the form from GRO again and, if it changed,
  clears its page cache.
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
  leaves it unticked, nothing is handed to the ad script and no hash is added
  to that page's address. The plugin makes no request of its own for this and
  does not store the hash.
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
