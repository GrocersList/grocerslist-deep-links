const MAX_AGE_MS_FALLBACK = 604800000; // 7 days in ms (7 * 24 * 60 * 60 * 1000)
const WIDGET_LOADED_AT = Date.now(); // good enough approximation of token timestamp as a fallback
const ONE_HOUR = 3600000; // 1 hour in ms (60 * 60 * 1000)

declare global {
  interface Window {
    grocersList: {
      WP_CLICK_TOKEN_MAX_AGE_MS?: number;
    };
  }
}

function setup() {
  window.addEventListener('focus', () => {
    const MAX_AGE_MS =
      (window.grocersList?.WP_CLICK_TOKEN_MAX_AGE_MS || MAX_AGE_MS_FALLBACK) -
      ONE_HOUR; // reload a bit before deadline

    const windowOpenMS = Date.now() - WIDGET_LOADED_AT;

    if (windowOpenMS > MAX_AGE_MS) {
      console.info(`Window has been open for ${windowOpenMS}. Reloading.`);
      window.location.reload();
    }
  });
}

setup();
