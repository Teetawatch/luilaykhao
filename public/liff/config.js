// LIFF app configuration.
//
// Fill these in after creating the LINE Login channel + LIFF app
// (see docs/LINE_LIFF_SETUP.md). They are read by app.js at runtime, so no
// build step is required — edit this file and redeploy public/liff/.
window.LIFF_CONFIG = {
  // The LIFF ID shown in the LINE Developers console (looks like 1234567890-AbCdEfGh).
  liffId: '__PUT_YOUR_LIFF_ID_HERE__',

  // Base URL of the Laravel API. Defaults to the same origin this page is
  // served from, which is correct when public/liff/ is hosted by the backend.
  apiBaseUrl: (location.origin || '') + '/api/v1',
};
