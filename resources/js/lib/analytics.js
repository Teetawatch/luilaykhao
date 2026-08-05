/**
 * GA4 + Meta Pixel, behind one consent gate.
 *
 * Every function here is a no-op unless (a) an id was configured server-side and
 * (b) the visitor has accepted the consent banner. Callers therefore never have
 * to check anything — instrument the page and let this decide whether it counts.
 *
 * The two vendors get the same events under their own names: GA4 wants
 * snake_case ecommerce events with an `items` array, Meta wants its fixed
 * vocabulary (ViewContent / InitiateCheckout / Purchase). Mapping lives here so
 * pages never learn either vocabulary.
 */

const CONSENT_KEY = 'analytics_consent';

const config = typeof window !== 'undefined' ? (window.__analytics || {}) : {};

let pixelLoaded = false;

// ── Consent ──────────────────────────────────────────────────────────────────

/** @returns {'granted'|'denied'|null} null when they have not answered yet */
export function consentState() {
  try {
    const stored = JSON.parse(localStorage.getItem(CONSENT_KEY) || 'null');
    if (!stored) return null;

    // An answer older than the configured window is treated as never given, so
    // the banner asks again rather than relying on a decision made a year ago.
    const ttlDays = config.consentTtlDays || 365;
    if (Date.now() - stored.at > ttlDays * 86400000) return null;

    return stored.state === 'granted' ? 'granted' : 'denied';
  } catch {
    return null;
  }
}

export function isConfigured() {
  return Boolean(config.gaId || config.pixelId);
}

/** Should the banner be shown? Only when there is something to consent to. */
export function needsConsentDecision() {
  return isConfigured() && consentState() === null;
}

export function setConsent(granted) {
  try {
    localStorage.setItem(CONSENT_KEY, JSON.stringify({
      state: granted ? 'granted' : 'denied',
      at: Date.now(),
    }));
  } catch {
    // Private-browsing mode can refuse writes. Failing to remember the answer
    // is survivable; failing to honour it right now is not, so carry on.
  }

  applyConsent(granted);
}

/**
 * Push the decision to Google, and load or withhold the Meta Pixel.
 *
 * Called on every boot as well as on the banner click: Consent Mode's default
 * state is denied, so a returning visitor who already said yes has to have that
 * replayed before anything they do this session is recorded.
 */
function applyConsent(granted) {
  if (typeof window === 'undefined') return;

  if (config.gaId && typeof window.gtag === 'function') {
    window.gtag('consent', 'update', {
      ad_storage: granted ? 'granted' : 'denied',
      ad_user_data: granted ? 'granted' : 'denied',
      ad_personalization: granted ? 'granted' : 'denied',
      analytics_storage: granted ? 'granted' : 'denied',
    });
  }

  if (granted && config.pixelId) {
    loadPixel();
  }
}

/**
 * Inject the Meta Pixel. Unlike Google's tag it has no "load but collect
 * nothing" mode, so it is not in the page at all until this runs.
 */
function loadPixel() {
  if (pixelLoaded || !config.pixelId) return;
  pixelLoaded = true;

  /* eslint-disable */
  !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
  n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
  n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
  t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}
  (window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
  /* eslint-enable */

  window.fbq('init', config.pixelId);
}

/** Replay a previously stored decision. Call once, at app boot. */
export function initAnalytics() {
  const state = consentState();
  if (state !== null) {
    applyConsent(state === 'granted');
  }
}

// ── Sending ──────────────────────────────────────────────────────────────────

function allowed() {
  return isConfigured() && consentState() === 'granted';
}

function ga(event, params) {
  if (config.gaId && typeof window.gtag === 'function') {
    window.gtag('event', event, params);
  }
}

function meta(event, params, options) {
  if (config.pixelId && typeof window.fbq === 'function') {
    window.fbq('track', event, params, options);
  }
}

export function pageView(path, title) {
  if (!allowed()) return;

  ga('page_view', {
    page_path: path,
    page_location: window.location.href,
    page_title: title || document.title,
  });
  meta('PageView');
}

/** Escape hatch for one-off events that are not part of the funnel. */
export function trackEvent(name, params = {}) {
  if (!allowed()) return;
  ga(name, params);
}

// ── Funnel ───────────────────────────────────────────────────────────────────

function tripItem(trip, extra = {}) {
  return {
    item_id: trip?.slug || String(trip?.id ?? ''),
    item_name: trip?.title || '',
    item_category: trip?.type || '',
    price: Number(trip?.min_price ?? trip?.price_per_person ?? 0),
    quantity: 1,
    ...extra,
  };
}

/** Someone opened a trip page. */
export function viewTrip(trip) {
  if (!allowed() || !trip) return;

  const item = tripItem(trip);

  ga('view_item', { currency: 'THB', value: item.price, items: [item] });
  meta('ViewContent', {
    content_ids: [item.item_id],
    content_name: item.item_name,
    content_type: 'product',
    value: item.price,
    currency: 'THB',
  });
}

/** Someone reached the booking form for a specific round. */
export function beginCheckout({ trip, scheduleId, seats = 1, value = 0 }) {
  if (!allowed()) return;

  const item = tripItem(trip, { quantity: seats, item_variant: String(scheduleId ?? '') });

  ga('begin_checkout', { currency: 'THB', value: Number(value) || item.price * seats, items: [item] });
  meta('InitiateCheckout', {
    content_ids: [item.item_id],
    content_type: 'product',
    num_items: seats,
    value: Number(value) || item.price * seats,
    currency: 'THB',
  });
}

/** A booking exists and the customer is on the payment screen. */
export function addPaymentInfo({ bookingRef, value, paymentType }) {
  if (!allowed()) return;

  ga('add_payment_info', {
    currency: 'THB',
    value: Number(value) || 0,
    payment_type: paymentType || 'promptpay',
    transaction_id: bookingRef,
  });
  meta('AddPaymentInfo', { value: Number(value) || 0, currency: 'THB' });
}

/**
 * The money event. transaction_id is the booking ref, which is what makes this
 * safe to fire again: both GA4 and Meta de-duplicate on it, and the confirmation
 * page is a URL people bookmark, refresh and reopen from their email.
 */
export function purchase({ bookingRef, value, trip, seats = 1 }) {
  if (!allowed() || !bookingRef) return;

  const item = tripItem(trip, { quantity: seats });

  ga('purchase', {
    transaction_id: bookingRef,
    currency: 'THB',
    value: Number(value) || 0,
    items: [item],
  });
  meta(
    'Purchase',
    { content_ids: [item.item_id], content_type: 'product', num_items: seats, value: Number(value) || 0, currency: 'THB' },
    { eventID: bookingRef },
  );
}

export default {
  initAnalytics,
  consentState,
  needsConsentDecision,
  setConsent,
  isConfigured,
  pageView,
  trackEvent,
  viewTrip,
  beginCheckout,
  addPaymentInfo,
  purchase,
};
