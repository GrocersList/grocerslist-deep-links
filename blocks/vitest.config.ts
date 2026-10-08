import { defineConfig } from 'vitest/config';

// A config of its own: vite.config.ts builds one script per --mode and
// refuses any other mode, the test mode included.
export default defineConfig({
  test: {
    include: ['tests/**/*.test.ts'],
    globalSetup: ['tests/globalSetup.ts'],
  },
});
