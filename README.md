=== Grocers List Deep Links for Amazon ===
Contributors: grocerslist
Requires at least: 6.2
Author: Grocers List, Engineering
Tested up to: 6.8
Stable tag: 1.0.0
Requires PHP: 7.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0
Automatically turn Amazon links on your blog into deep links that open the click into the amazon app - 3-5X more likely to buy.

# Grocers List Deep Links for Amazon

## About [Grocers List](https://grocerslist.com)

Grocers List is an influencer monetization platform that boosts ROI by converting standard affiliate links (like Amazon links) into links that open directly in a mobile device’s native app.

## External Services

This plugin connects to Grocers List APIs to generate deep links for affiliate URLs.

- Service: Grocers List (https://www.grocerslist.com/amazon-app-links)
- Purpose: Convert standard affiliate links into native-app deep links.
- Data Sent: URLs provided for conversion, plus non-PII HTTP metadata (e.g device type, and other non-identifiable information)

Legal:

- [Creator Terms of Service](https://www.grocerslist.com/creator-tos)
- [Privacy Policy](https://www.grocerslist.com/privacy)

---

## Source Code

The original, unminified source code for the plugin’s JavaScript and PHP code
is available in this repository and will continue to be up to date as we make future improvements

### Runtime Dependencies

These packages are included in the distributed plugin bundle:

1. **[@emotion/react](https://www.npmjs.com/package/@emotion/react)**

   - Styles UI with CSS-in-JS directly in JavaScript/Preact components.

2. **[@emotion/styled](https://www.npmjs.com/package/@emotion/styled)**

   - Provides “styled-component” syntax for reusable visual components.

3. **[@mui/material](https://www.npmjs.com/package/@mui/material)**

   - Google’s Material Design UI kit for building UI components like buttons, dialogs, etc.

4. **[@mui/icons-material](https://www.npmjs.com/package/@mui/icons-material)**

   - SVG icon set matching Material UI.

5. **[@mui/lab](https://www.npmjs.com/package/@mui/lab)**

   - Experimental Material UI components (date pickers, timeline, etc.).

6. **[preact](https://www.npmjs.com/package/preact)**

   - Lightweight (~3 KB) alternative to React for smaller bundles.

7. **[react-hot-toast](https://www.npmjs.com/package/react-hot-toast)**

   - Pop-up toast notifications for user feedback.

8. **[react-spinners](https://www.npmjs.com/package/react-spinners)**
   - Loading spinners to show activity indicators.

---

### Development-Only Dependencies (Not included in the plugin distribution asset)

These packages are used only during development and do **not** ship in the production bundle:

1. **[@eslint/js](https://www.npmjs.com/package/@eslint/js)** & **[eslint](https://www.npmjs.com/package/eslint)**

   - Linting tools to catch typos and code issues before release.

2. **[typescript](https://www.npmjs.com/package/typescript)** & **[typescript-eslint](https://www.npmjs.com/package/typescript-eslint)**

   - TypeScript compiler and linter rules for type safety and early bug detection.

3. **[@types/node](https://www.npmjs.com/package/@types/node)**

   - Type definitions for Node.js globals.

4. **[@preact/preset-vite](https://www.npmjs.com/package/@preact/preset-vite)**

   - Vite plugin to automatically swap React for Preact.

5. **[vite](https://www.npmjs.com/package/vite)**

   - Modern build tool for fast bundling, optimization, and hot reloads.

6. **[globals](https://www.npmjs.com/package/globals)**
   - Helper list for ESLint to recognize standard global variables.

---

Only the runtime dependencies listed above are bundled and shipped with the plugin. Development-only tools remain purely for developer workflow and local builds.
<br>

<hr>

### Questions or concerns?

Reach up at **[supoort@grocerslist.com](mailto:supoort@grocerslist.com)**
