## External Services

This plugin connects to Grocers List APIs to generate deep links for affiliate URLs.

- Service: Grocers List (https://www.grocerslist.com)
- Purpose: Convert standard affiliate links into native-app deep links.
- Data Sent: URLs provided for conversion, plus non-PII HTTP metadata.

Legal:

- [Creator Terms of Service](https://www.grocerslist.com/creator-tos)
- [Privacy Policy](https://www.grocerslist.com/privacy)

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

---
