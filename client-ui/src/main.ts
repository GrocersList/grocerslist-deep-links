(() => {
  const setAdSelectorProcessed = () => {
    setTimeout(() => {
      /**
       * This is a fallback to make sure ads get shown after 10 seconds
       * if something goes wrong in the widget code.
       **/
      document.body.classList.add('grocers-list-ads-processed');
    }, 10000);
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setAdSelectorProcessed);
  } else {
    setAdSelectorProcessed();
  }
})();
