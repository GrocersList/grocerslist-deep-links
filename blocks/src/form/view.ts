// Drives every GRO form on the page. What differs between them is in each
// form's own markup: its BEM block in data-gl-form, its kind in
// data-gl-kind, the event it fires in data-gl-event, its copy in
// data-gl-i18n and, when a successful submit leaves for another page, that
// page in data-gl-redirect.
//
// It also opens content gates ([data-gl-gate]): a gate's HTML never holds
// what it hides, since a page cache gives everyone the same page, so the
// content comes from the gate route (data-gl-reveal) for a browser the
// server recognizes, or with the answer to the gate's own form, and takes
// the gate's place in the page.

interface Answer {
  // What the submit route puts in every 2xx of its own, and nothing else
  // does: ok exactly true, and a status word for what it did
  // (FormsController::groStatus()). Left unknown rather than typed, since
  // both are read from whatever answered: a refusal's problem+json carries
  // an integer HTTP code in status.
  ok?: unknown;
  status?: unknown;
  // The route's own copy for a refusal, shown as it stands — so unknown too,
  // and only ever shown when it is a string (messageFor).
  detail?: unknown;
  code?: string;
  // The fields GRO rejected, by name; the plugin never passes on its wording.
  errors?: Array<{ field?: unknown } | null>;
  // What the gate the form sits in hides, once the visitor has subscribed.
  gate?: { html?: unknown } | null;
  // Sent only when the creator has Advanced Tracking on in GRO: GRO's hash
  // of the subscriber's address (SHA-256 hex of it, trimmed and lowercased)
  // and which of the site's ad networks to hand it to.
  advancedTracking?: {
    subscriberHash?: unknown;
    mediavine?: unknown;
    raptive?: unknown;
  } | null;
}

declare global {
  interface Window {
    grocersList?: { member?: { email?: string } };
    // What FormRenderer prints before this script: rest_url(), the REST
    // API's root (see restRoot).
    grocerslistForms?: { restRoot?: unknown };
    // Raptive's command queue, which its script runs once it has loaded,
    // and its Identity API.
    adthrive?: {
      cmd?: Array<() => void>;
      identityApi?: (
        identity: { source: string; sha256: string },
        done: (result: unknown) => void
      ) => void;
    };
    // Mediavine's Identity API, there once it fires mediavineIdentityReady.
    $adManagementConfig?: {
      web?: {
        identityOptIn?: (
          identity: { email: string },
          done: (error: unknown) => void
        ) => void;
      };
    };
  }
}

const STORAGE_KEY = 'grocerslist:form';
const NUMERIC_FIELDS = ['ts', 'postId', 'recipeId', 'gatePostId'];
// The submitted fields the event's detail passes on as they were sent. The
// address is not one of them, raw or hashed: anything on the page can
// listen, and an address reaching an analytics or ad tag is against GA4's
// and Meta's terms (see docs/FORMS.md → Event detail).
const EVENT_FIELDS = ['formId', 'postId', 'recipeId'];
// A status word as the route writes one, matching FormsController's own
// check of GRO's: lowercase letters and underscores, at most 32.
const STATUS_WORD = /^[a-z_]{1,32}$/;
// Set beside the httponly cookie the gate route checks, which no script can
// read: only whether asking the route is worth a request.
const HINT_COOKIE = 'gl_forms_subscribed_hint';
const OPEN = 'gl-gate--open';
// The block renders a gate as a div. One written as a form is markup a
// post's author chose, whose controls could stand in for its own methods.
const LOCKED_GATES = `[data-gl-gate]:not(form):not(.${OPEN})`;
// A gate an editor previews as a visitor (data-gl-preview) stays locked for
// a browser the server recognizes: only its own form's answer opens it.
const UNLOCKABLE_GATES = `${LOCKED_GATES}:not([data-gl-preview])`;
// The plugin's routes, as rest_url() builds them: …/grocerslist/v1/<route>
// after any subdirectory and REST prefix, or with plain permalinks
// …/index.php?rest_route=/grocerslist/v1/<route> (…/?rest_route= before).
const GATE_ROUTE = /^\/grocerslist\/v1\/gate\/\d+\/[\w-]+$/;
const SUBMIT_ROUTE = /^\/grocerslist\/v1\/forms\/submit$/;
// A parameter PHP cannot read as rest_route or as one of the REST API's own
// (_method, _envelope…): no "_", ".", space or "[" in its name.
const OTHER_PARAM = /^[a-z0-9-]+$/i;
// A path segment the server can run as a script, handing it the rest of the
// path: …/wp-admin/admin-ajax.php/grocerslist/v1/… answers as any AJAX
// action. The only one rest_url() writes is WordPress's own index.php.
const SCRIPT = /\.ph(p|t|ar)/i;
// GRO's hash of a subscriber's address, as its links carry it.
const SUBSCRIBER_HASH = /^[a-f0-9]{64}$/;
// GRO's cap on design.redirectUrl, in UTF-16 code units as String.length
// counts them.
const MAX_REDIRECT_LENGTH = 2048;
// GRO's own domains, linksta.io (its short links) among them. A hash on an
// address there reaches no ad network, only GRO's logs, and a short link
// drops the query when it redirects anyway.
const GRO_DOMAINS = ['gro.co', 'grocerslist.com', 'linksta.io'];
// The dot in the domain that WordPress's is_email() asks for (two labels at
// least): the browser's own check of an email field takes user@localhost.
const DOTTED_DOMAIN = /@[^\s@]+\.[^\s@]+$/;

// A value no second submit repeats: the moment, and a random tail, both in
// base 36. Not crypto.randomUUID(), which a browser exposes only in a secure
// context — a creator's site still served over plain http has window.crypto
// with no randomUUID on it, and reading it as a function would throw where
// nothing may.
const token = (): string =>
  Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);

type Messages = {
  busy: string;
  generic: string;
  stale: string;
  // Only for a 429 with no detail of its own, such as a host firewall's
  // page: the submit route's 429s say how long to wait.
  throttled: string;
  // What is wrong with the address, said before anything is sent.
  emailMissing: string;
  emailInvalid: string;
  // Sent with a consent box the visitor must tick.
  consent: string;
  // Only forms that send it show the returning-visitor line.
  returning?: string;
};

// Fallbacks only: the rendered form carries the translated copy in
// data-gl-i18n, so nothing user-facing has to ship inside this bundle.
const FALLBACK_MESSAGES: Messages = {
  busy: 'Subscribing…',
  generic: 'Something went wrong. Please try again.',
  stale: 'Please reload the page and try again.',
  throttled: 'Too many signups from here. Please try again later.',
  emailMissing: 'Please enter your email address.',
  emailInvalid: 'Please enter a valid email address.',
  consent: 'Tick the box to sign up.',
};

// How long a form that is leaving for its redirect waits for the next page
// before it shows its success message after all.
const REDIRECT_FALLBACK_MS = 4000;

// Elements never shown, so neither where a link to the gate lands nor where
// reading goes on: a block rendered outside the page's head (as in the gate
// route) may print its own <style>, or a <script>, before its markup.
const UNSEEN = ['LINK', 'META', 'NOSCRIPT', 'SCRIPT', 'STYLE', 'TEMPLATE'];

// An element's attribute as written. A control named getAttribute would
// stand in for a form's own method (as one named action does for
// form.action).
const attr = (element: Element, name: string): string | null =>
  Element.prototype.getAttribute.call(element, name);

// document's own members, read past the page's markup: a form's name makes
// the form a property of document that wins over document's own
// (name="querySelectorAll", name="cookie"…), and a post's author can write
// a form.
const all = <E extends Element>(selector: string): E[] =>
  Array.from(
    Document.prototype.querySelectorAll.call(document, selector)
  ) as E[];

const create = (tag: string): HTMLElement =>
  Document.prototype.createElement.call(document, tag);

const COOKIE = Object.getOwnPropertyDescriptor(Document.prototype, 'cookie');

const cookies = (): string =>
  COOKIE && COOKIE.get ? String(COOKIE.get.call(document)) : document.cookie;

const readMessages = (form: HTMLFormElement): Messages => {
  const messages = { ...FALLBACK_MESSAGES };

  try {
    const parsed = JSON.parse(attr(form, 'data-gl-i18n') || '{}') as Partial<
      Record<keyof Messages, unknown>
    >;

    (
      [
        'busy',
        'generic',
        'stale',
        'throttled',
        'emailMissing',
        'emailInvalid',
        'consent',
        'returning',
      ] as Array<keyof Messages>
    ).forEach(key => {
      const value = parsed[key];
      if (typeof value === 'string' && value !== '') {
        messages[key] = value;
      }
    });
  } catch {
    // Malformed attribute — the English fallbacks still read fine.
  }

  return messages;
};

// elapsedMs's clock where performance.now() is missing: when this script
// ran, which is never before the navigation started.
const loadedAt = Date.now();

interface Stored {
  email?: string;
  // false once the address was only used to save a page, opt-in unticked:
  // it is not on the creator's list, so no "already on the list" line.
  listed?: boolean;
}

const readStored = (): Stored => {
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY);
    const parsed = raw ? (JSON.parse(raw) as Stored) : null;
    return parsed && typeof parsed.email === 'string' ? parsed : {};
  } catch {
    return {};
  }
};

const remember = (email: string, listed: boolean): void => {
  const stored = readStored();

  try {
    window.localStorage.setItem(
      STORAGE_KEY,
      JSON.stringify({
        email,
        listed: listed || (stored.email === email && stored.listed !== false),
        at: Date.now(),
      })
    );
  } catch {
    // Storage blocked (private mode, cookie walls) — the submission worked.
  }
};

// What the route says it did, or null when the answer is not the route's.
// The route owns its 2xx: ok exactly true and a status word. A WAF, a bot
// challenge or a maintenance page in front of /wp-json answers 2xx too, and
// a body that would not parse arrives here as {}, while a 2xx body of
// literally null arrives as null; none of them wrote that shape, and nothing
// reached GRO, so none of them is a submission that succeeded. The null is
// read for what it is rather than left to throw into the catch below: what
// decides success has to say no itself, not by way of an exception.
const outcomeOf = (body: Answer | null): string | null =>
  body != null &&
  body.ok === true &&
  typeof body.status === 'string' &&
  STATUS_WORD.test(body.status)
    ? body.status
    : null;

// What the status line says when the route refuses a submit: its own detail,
// which on a 429 says how long to wait, but this form's own line for a stale
// page. The route always writes that detail as a string, so a detail that is
// anything else — missing, or an object a host's or firewall's page answered
// with — is not the route's wording and never reaches the status line.
const messageFor = (
  status: number,
  problem: Answer | null,
  messages: Messages
): string => {
  if (problem?.code === 'invalid_signature') {
    return messages.stale;
  }
  const detail = typeof problem?.detail === 'string' ? problem.detail : '';
  if (status === 429 && detail === '') {
    return messages.throttled;
  }
  return detail || messages.generic;
};

// Whether the address is what failed, the plugin's check of it or GRO's.
// GRO's field errors can name something the visitor never typed, like the
// page the plugin filled in; without them, the route's copy asks the
// visitor to check their address.
const addressRejected = (problem: Answer | null): boolean => {
  if (problem?.code === 'invalid_email') {
    return true;
  }
  if (problem?.code !== 'upstream_rejected') {
    return false;
  }

  const errors = Array.isArray(problem.errors) ? problem.errors : [];
  return errors.length === 0 || errors.some(error => error?.field === 'email');
};

const hasHint = (): boolean =>
  `; ${cookies()}`.indexOf(`; ${HINT_COOKIE}=`) !== -1;

const reducedMotion = (): boolean =>
  typeof window.matchMedia === 'function' &&
  window.matchMedia('(prefers-reduced-motion: reduce)').matches;

// Reading goes on from the first element revealed; the form that had focus
// is gone.
const focusStart = (element: HTMLElement): void => {
  const focusable = element.hasAttribute('tabindex');
  if (!focusable) {
    element.tabIndex = -1;
  }
  element.classList.add('gl-gate-start');
  element.addEventListener(
    'blur',
    () => {
      element.classList.remove('gl-gate-start');
      if (!focusable) {
        element.removeAttribute('tabindex');
      }
    },
    { once: true }
  );
  element.focus();
};

// What the gate hides, in the gate's place: the site's own post content,
// rendered by WordPress for this visitor. The gate itself goes, so the
// revealed blocks are the post's own, laid out as the rest of it is (a block
// theme spaces and aligns a post's blocks as children of its content).
const open = (gate: HTMLElement, html: string, focus: boolean): void => {
  if (!gate.isConnected || gate.classList.contains(OPEN)) {
    return;
  }

  const template = create('template') as HTMLTemplateElement;
  template.innerHTML = html;
  const content = template.content;
  const forms = Array.from(
    content.querySelectorAll<HTMLFormElement>('form[data-gl-form]')
  );
  const elements = (Array.from(content.children) as HTMLElement[]).filter(
    element => !UNSEEN.includes(element.tagName)
  );
  const first = elements.length ? elements[0] : null;
  // Where reading goes on: the first element with something to read or see.
  const start =
    elements.find(
      element =>
        (element.textContent || '').trim() !== '' ||
        element.querySelector('img, picture, video, iframe, svg, input') !==
          null
    ) || first;

  // The gate's id (its anchor) stays where the gate was, so a link to it
  // still lands there.
  if (gate.id) {
    if (first && !first.id) {
      first.id = gate.id;
    } else {
      const mark = create('span');
      mark.id = gate.id;
      content.insertBefore(mark, content.firstChild);
    }
  }

  // Marked open before it goes, for a submit whose answer arrives after.
  gate.classList.add(OPEN);
  gate.replaceWith(content);

  forms.forEach(wire);

  if (!reducedMotion()) {
    elements.forEach(element => {
      if (typeof element.animate === 'function') {
        element.animate([{ opacity: 0 }, { opacity: 1 }], {
          duration: 400,
          easing: 'ease-out',
        });
      }
    });
  }

  if (focus && start) {
    focusStart(start);
  }
};

// The REST API's root as rest_url() gives it, which FormRenderer prints
// before this script, or null without one. Unlike a form's action or a
// gate's data-gl-reveal, it is nothing a post's author can write. Only a
// string counts: an element whose id or name is grocerslistForms (DOM
// clobbering) is no root, nor is a frame of that name, which throws when
// read.
const restRoot = (): string | null => {
  try {
    const root = window.grocerslistForms?.restRoot;
    return typeof root === 'string' ? root : null;
  } catch {
    return null;
  }
};

// What follows prefix in value, from the "/" that ends prefix (one is added
// when it has none), or null when value does not start with it.
const after = (prefix: string, value: string): string | null => {
  const start = prefix.replace(/\/?$/, '/');
  return value.indexOf(start) === 0 ? value.slice(start.length - 1) : null;
};

// The route url names under root, as WordPress reads it: with pretty
// permalinks the path after root's (…/wp-json/grocerslist/v1/…), with plain
// ones the rest_route after root's, on root's own path
// (…/index.php?rest_route=/grocerslist/v1/…). So neither another plugin's
// catch-all route that ends in ours
// (…/wp-json/<namespace>/v1/<anything>/grocerslist/v1/…) nor an index.php
// other than WordPress's own (/wp-admin/index.php/…, /wp-admin/?rest_route=…,
// a plugin folder's) is ours.
const routeUnder = (
  root: string,
  url: URL,
  param: string | null
): string | null => {
  let base: URL;
  try {
    base = new URL(root);
  } catch {
    return null;
  }

  const baseParam = base.searchParams.get('rest_route');
  if (base.origin !== url.origin) {
    return null;
  }
  if (baseParam === null) {
    return param === null ? after(base.pathname, url.pathname) : null;
  }
  return param !== null && url.pathname === base.pathname
    ? after(baseParam, param)
    : null;
};

// The route at the end of url, for a page rendered without the root: one a
// page cache kept from a build that did not print it yet (only pre-release
// staging builds rendered GRO forms without it; 1.30.0 ships it), or whose
// optimizer runs this script before the inline one. The path after its last
// /grocerslist/, or the rest_route on a path ending in / or /index.php, and
// no script on the path but index.php: the only one rest_url() writes, while
// …/wp-admin/admin-ajax.php/grocerslist/v1/… answers as any AJAX action.
// Another plugin's catch-all route and a folder's own index.php get through.
const routeAtTail = (url: URL, param: string | null): string | null => {
  let segments: string[];
  try {
    // Decoded, as the server finds the script: admin-ajax%2Ephp runs too.
    segments = decodeURIComponent(url.pathname).split('/');
  } catch {
    return null;
  }
  if (
    segments.some(segment => segment !== 'index.php' && SCRIPT.test(segment))
  ) {
    return null;
  }

  const path = url.pathname;
  if (param === null) {
    return path.slice(path.lastIndexOf('/grocerslist/'));
  }
  return /\/(index\.php)?$/.test(path) ? param : null;
};

// value as the URL of this site's own route matching pattern, else null.
// data-gl-reveal and a form's action are page markup, which anyone who can
// write a post can write too (kses keeps data-* attributes, and a plugin may
// let authors post forms), and what the gate and submit routes answer goes
// into the page as HTML: asked anywhere else, a server of their choosing, or
// a file they uploaded, would answer with script. rest_url() always gives an
// absolute URL with no user name or password, so nothing else is ours, and
// with the REST root on the page nothing but that root and the route.
const ownRoute = (value: string | null, pattern: RegExp): URL | null => {
  let url: URL;
  try {
    url = new URL(value || '');
  } catch {
    return null;
  }

  // PHP can be set to split parameters at ";" too (arg_separator.input),
  // which would hide one from the names checked below.
  if (
    !/^https?:$/.test(url.protocol) ||
    url.origin !== window.location.origin ||
    url.username !== '' ||
    url.password !== '' ||
    url.search.indexOf(';') !== -1
  ) {
    return null;
  }

  let param: string | null = null;
  for (const [key, entry] of url.searchParams) {
    if (key === 'rest_route' && param === null) {
      param = entry;
    } else if (!OTHER_PARAM.test(key)) {
      return null;
    }
  }

  const root = restRoot();
  const route =
    root === null ? routeAtTail(url, param) : routeUnder(root, url, param);

  return route !== null && pattern.test(route) ? url : null;
};

const unlock = (gate: HTMLElement): void => {
  const target = ownRoute(attr(gate, 'data-gl-reveal'), GATE_ROUTE);
  if (!target || attr(gate, 'aria-busy') !== null) {
    return;
  }

  // A URL no cache has seen: the answer depends on a cookie, and a host
  // cache that ignores no-store must never hand one visitor's to another.
  target.searchParams.set('_', Math.random().toString(36).slice(2));

  gate.setAttribute('aria-busy', 'true');
  fetch(target.href, {
    credentials: 'same-origin',
    // Wherever a redirect leads is not the route, whatever it answers.
    redirect: 'error',
    headers: { Accept: 'application/json' },
  })
    .then(response => (response.status === 200 ? response.json() : null))
    // The gate route owns its 200 as the submit route owns its 2xx
    // (outcomeOf): ok exactly true, and the content as a string. A WAF, a bot
    // challenge or a maintenance page in front of /wp-json answers 200 too
    // and carries neither, and what it answered with would go into the page
    // as HTML.
    .then((body: { ok?: unknown; html?: unknown } | null) => {
      if (body && body.ok === true && typeof body.html === 'string') {
        open(gate, body.html, false);
      }
    })
    .catch(() => undefined)
    .then(() => gate.removeAttribute('aria-busy'));
};

// Each gate on its own: any element can carry data-gl-gate, and one whose
// markup throws never keeps the next shut.
const unlockAll = (): void => {
  if (hasHint()) {
    all<HTMLElement>(UNLOCKABLE_GATES).forEach(gate =>
      quietly(() => unlock(gate))
    );
  }
};

// data-gl-redirect as an absolute http(s) URL, else null. The renderer writes
// no other kind, but the attribute is page markup, which a post's author can
// write too (kses keeps data-* attributes), and a javascript: URL would run.
// As GRO rules too: no user name or password ("https://amyskitchen.com@
// evil.com" goes to evil.com), and no more than its cap.
const redirectTarget = (value: string | null): URL | null => {
  if (!value || value.length > MAX_REDIRECT_LENGTH) {
    return null;
  }

  try {
    const url = new URL(value);
    return (url.protocol === 'https:' || url.protocol === 'http:') &&
      url.username === '' &&
      url.password === ''
      ? url
      : null;
  } catch {
    return null;
  }
};

// Whether url is on one of GRO's domains: the domain itself or a subdomain,
// never a host that merely contains one (notgro.co, gro.co.evil.com). A URL's
// hostname is lowercase already.
const groOwned = (url: URL): boolean =>
  GRO_DOMAINS.some(
    domain => url.hostname === domain || url.hostname.endsWith(`.${domain}`)
  );

// Sets name to value on url as URLSearchParams.set() does (the first
// parameter of that name takes the value and any other goes, or it is added
// at the end), but leaves every other parameter as written, and the
// fragment: set() writes the whole query back in its own encoding (%20 as
// +), which a server that reads + as a plus would read differently.
const setParam = (url: URL, name: string, value: string): void => {
  const pairs = url.search
    .slice(1)
    .split('&')
    .filter(pair => pair !== '');
  // Read as the query reads it: the constructor drops a leading '?', which in
  // a query is part of the name (??sh_mv=1 names ?sh_mv, not sh_mv).
  const named = (pair: string) => new URLSearchParams(`&${pair}`).has(name);
  const at = pairs.findIndex(named);
  const kept = pairs.filter(pair => !named(pair));
  kept.splice(at === -1 ? kept.length : at, 0, `${name}=${value}`);
  url.search = '?' + kept.join('&');
};

const noop = () => undefined;

// The hand-off to an ad network is never the form's concern: whatever it
// throws, now or when the network's script runs what it was handed, the
// form carries on as if it had not happened, and the console stays quiet.
const quietly = (call: () => unknown): void => {
  try {
    const result = call() as PromiseLike<unknown> | null | undefined;
    // An async API fails with a rejected promise, which the console reports
    // as uncaught unless something handles it.
    if (result && typeof result.then === 'function') {
      result.then(undefined, noop);
    }
  } catch {
    // Nothing for the form to show.
  }
};

// Advanced Tracking: gives the site's own ad network GRO's hash of the
// subscriber's address, as GRO's links do, for this page view only (nothing
// is stored), in the shape FormsController passes on: a SHA-256 hex hash
// and a boolean for each network. Either network's script may not have
// loaded yet.
const identify = (answer: Answer | null, redirect: URL | null): void => {
  const {
    subscriberHash: hash,
    mediavine,
    raptive,
  } = answer?.advancedTracking || {};
  if (
    typeof hash !== 'string' ||
    !SUBSCRIBER_HASH.test(hash) ||
    typeof mediavine !== 'boolean' ||
    typeof raptive !== 'boolean'
  ) {
    return;
  }

  if (raptive) {
    quietly(() => {
      // Raptive runs its queue once its script has loaded, API and all. Each
      // is created only when it is not there yet, as Raptive's own snippet
      // does: what its script put there may not take a new value.
      const adthrive = window.adthrive || (window.adthrive = {});
      (adthrive.cmd || (adthrive.cmd = [])).push(() =>
        quietly(() =>
          window.adthrive!.identityApi!({ source: 'gro', sha256: hash }, noop)
        )
      );
    });
  }

  if (mediavine) {
    // Whether Mediavine's API was there to take it: its script can define
    // $adManagementConfig.web before the method on it.
    const optIn = (): boolean => {
      const web = window.$adManagementConfig?.web;
      if (typeof web?.identityOptIn !== 'function') {
        return false;
      }
      quietly(() => web.identityOptIn!({ email: hash }, noop));
      return true;
    };
    quietly(() => {
      if (!optIn()) {
        window.addEventListener(
          'mediavineIdentityReady',
          () => quietly(optIn),
          {
            once: true,
          }
        );
      }
    });
  }

  // The next page replaces this one at once, before either call may have
  // finished: the networks read the same hash off its address, as they do
  // off a GRO link's. An address of GRO's own is left exactly as it is.
  if (redirect && !groOwned(redirect)) {
    if (mediavine) {
      setParam(redirect, 'sh_mv', hash);
    }
    if (raptive) {
      setParam(redirect, 'adt_eih', hash);
    }
  }
};

// For each form leaving for its redirect, what shows its success message
// after all.
const staying = new Set<() => void>();

const setup = (form: HTMLFormElement): void => {
  const block = attr(form, 'data-gl-form') || 'gl-form';
  const status = form.querySelector<HTMLElement>(`.${block}__status`);
  const fields = form.querySelector<HTMLElement>(`.${block}__fields`);
  const submit = form.querySelector<HTMLButtonElement>(`.${block}__submit`);
  const email = form.querySelector<HTMLInputElement>('input[name="email"]');
  const success = form.querySelector<HTMLTemplateElement>(
    `template.${block}__success`
  );

  if (!status || !fields || !submit || !email) {
    return;
  }

  // A signup or content-gate form's consent box. The form is novalidate so
  // that the status line, not a browser bubble, says what is wrong with an
  // address; this is the one field the browser is asked to report on.
  const consent = form.querySelector<HTMLInputElement>(
    `.${block}__optin input[required]`
  );
  const messages = readMessages(form);
  const kind = attr(form, 'data-gl-kind') || '';
  const eventName = attr(form, 'data-gl-event') || 'grocerslist:subscribed';

  let inFlight = false;

  // Ticking the box answers what an unticked submit said about it, so a
  // screen reader stops calling a ticked box invalid.
  if (consent) {
    consent.addEventListener('change', () => {
      if (!consent.checked) {
        return;
      }
      consent.removeAttribute('aria-invalid');
      if (status.textContent === messages.consent) {
        status.textContent = '';
      }
    });
  }

  // Editing the address answers what was said about it, by the submit below
  // or by the route (invalid_email, upstream_rejected), so a screen reader
  // stops calling it invalid. The status line goes only when it holds one of
  // this form's lines about the address; the route's invalid_email detail is
  // emailInvalid word for word.
  email.addEventListener('input', () => {
    email.removeAttribute('aria-invalid');
    if (
      status.textContent === messages.emailMissing ||
      status.textContent === messages.emailInvalid
    ) {
      status.textContent = '';
    }
  });

  const showSuccess = () => {
    form.classList.add(`${block}--done`);
    status.innerHTML = success ? success.innerHTML : '';
    fields.remove();
    // The button that had focus just went away with the fields.
    status.focus();
  };

  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (inFlight) {
      return;
    }

    // A blank or mistyped address costs no request, nor a slot in the
    // route's per-IP limit. Focusing the field says why: it is described by
    // the status line.
    const address = email.value.trim();
    if (
      address === '' ||
      !email.validity.valid ||
      !DOTTED_DOMAIN.test(address)
    ) {
      email.setAttribute('aria-invalid', 'true');
      status.textContent =
        address === '' ? messages.emailMissing : messages.emailInvalid;
      email.focus();
      return;
    }

    if (consent && !consent.checked) {
      consent.setAttribute('aria-invalid', 'true');
      status.textContent = messages.consent;
      consent.focus();
      consent.reportValidity();
      return;
    }

    inFlight = true;
    let leaving = false;
    const label = submit.textContent || '';
    form.setAttribute('aria-busy', 'true');
    submit.textContent = messages.busy;
    status.textContent = '';
    email.removeAttribute('aria-invalid');
    consent?.removeAttribute('aria-invalid');

    const data = new FormData(form);
    const payload: Record<string, unknown> = {
      // Timed from the navigation's start, not from this script or the
      // visitor's first keystroke: a view script printed in the footer, or
      // one a "delay JS" optimizer holds back until the first interaction,
      // starts late, and an address the browser or a password manager fills
      // in takes no typing. Starting earlier only ever makes a human's time
      // longer. Whole milliseconds: the route declares an integer, and
      // WordPress refuses a fraction before the route runs.
      elapsedMs: Math.round(
        typeof performance !== 'undefined' && performance.now
          ? performance.now()
          : Date.now() - loadedAt
      ),
    };
    // A later field of the same name wins, which is how a ticked opt-in's
    // subscribe=1 overrides the hidden subscribe=0 before it.
    data.forEach((value, key) => {
      payload[key] = NUMERIC_FIELDS.includes(key) ? Number(value) : value;
    });

    // Only the plugin's own submit route is trusted to say the visitor
    // subscribed, and with what a gate hides. Any other action is posted to
    // as the form itself would post: relative to the page, or to the page
    // when empty.
    const route = ownRoute(attr(form, 'action'), SUBMIT_ROUTE);

    try {
      // same-origin, not omit: a subscription's answer sets the content
      // gate's cookies, and a browser drops the Set-Cookie of a request made
      // without credentials. No redirect: wherever one leads is not the
      // route, and a POST sent on by 301 or 302 has lost its body anyway.
      const response = await fetch(
        route ? route.href : attr(form, 'action') || window.location.href,
        {
          method: 'POST',
          credentials: 'same-origin',
          redirect: 'error',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
          },
          body: JSON.stringify(payload),
        }
      );
      // Null for a 2xx whose body is literally null, which json() resolves
      // to: nothing below may take the answer for an object.
      const body = (await response.json().catch(() => ({}))) as Answer | null;

      if (response.ok) {
        // Only the route's own answer says a submission succeeded. Any other
        // action's belongs to a third party, and the form is a plain form
        // there (below), as it has always been.
        const outcome = route ? outcomeOf(body) : null;

        // A 2xx the route did not write: something in front of /wp-json
        // answered for it, or the body was unreadable. Nothing was sent, so
        // this is a failure like any other — messages.generic rather than
        // messageFor(), which reads refusals of the route's own.
        if (route && outcome === null) {
          status.textContent = messages.generic;
          status.focus();
          return;
        }

        // The honeypot (PublicFormController::screen): a caught submission is
        // told near enough what a real one is told, and nothing else happens
        // — nothing remembered, no event, no redirect, no gate opened, no ad
        // network. Inside a content gate the form is replaced by the success
        // message while the gate stays shut, which is the whole point; on a
        // form with a redirect the message is where a real one leaves.
        if (outcome === 'ignored') {
          showSuccess();
          return;
        }

        // A gate opens in place of its own form, so that form never leaves
        // for a redirect, whatever its markup says: the renderer writes none
        // there, but a page cached before it stopped can still have one. The
        // submit route's answer carrying a gate's content marks the form as a
        // gate's even when its gate opened through the gate route while this
        // submit was in flight, taking the form out of the page. A gate an
        // editor previews opens here too: the answer is this submit's.
        const gate = form.closest<HTMLElement>(LOCKED_GATES);
        const revealed =
          route && body?.gate && typeof body.gate.html === 'string'
            ? body.gate.html
            : null;
        const redirect =
          gate || revealed !== null
            ? null
            : redirectTarget(attr(form, 'data-gl-redirect'));
        if (!redirect) {
          showSuccess();
        }

        // An outcome is the route's answer and only ever that, the two
        // readings above having returned for the rest. Another action's
        // answer says nothing about this site's list: the form is a plain
        // form there, with nothing remembered and no event.
        if (outcome !== null) {
          // A box the page drew that came back unticked declines the
          // creator's list and, with it, the hand-off to the page's ad
          // network. FormRenderer prints the hidden subscribe=0 only beside
          // the box, so a '0' is always a box the visitor was shown.
          const declinedOptIn =
            data.has('subscribe') && payload.subscribe === '0';

          remember(String(data.get('email') || ''), !declinedOptIn);

          // One id for this submit, carried by the event and by the redirect
          // as gl_eid, so a tag that reports the conversion here and a
          // thank-you page that reports it again are reporting one conversion
          // (Meta's eventID, a GA4 dedup key). It names the submit and not the
          // visitor: a second submit of the same address is a new id, since
          // GRO answers a new, an existing and a suppressed address alike and
          // nothing here can tell them apart.
          const eventId = token();

          // Version 1 of the detail (docs/FORMS.md → Event detail): what was
          // signed up for, where and how, and never who.
          const detail: Record<string, string | boolean> = {
            eventId,
            kind,
            status: outcome,
            // GRO's own reading of the box, as remember() takes it: false
            // only when the page showed one and it came back unticked.
            subscribe: payload.subscribe !== '0',
            // Whether the page showed the box at all: one that does always
            // posts its hidden 0, one that does not posts no subscribe.
            optInShown: data.has('subscribe'),
          };
          // Left out rather than guessed on a page cached before this
          // attribute shipped: every shortcode and gate form on such a page
          // would be called a block, and a listener can tell a key that is
          // missing from one that is wrong.
          const placement = attr(form, 'data-gl-placement');
          if (placement) {
            detail.placement = placement;
          }
          EVENT_FIELDS.forEach(key => {
            if (data.has(key)) {
              detail[key] = String(data.get(key));
            }
          });
          // On the document once the form has gone, its gate opened through
          // the gate route while this submit was in flight, so listeners
          // there still hear it.
          EventTarget.prototype.dispatchEvent.call(
            form.isConnected ? form : document,
            new CustomEvent(eventName, { bubbles: true, detail })
          );

          // What follows only adds to the page and to where the form is
          // going, so nothing in it may reach the catch below: its message
          // would replace the success message, or keep the form from leaving
          // for its redirect.
          quietly(() => {
            // An address of GRO's own goes exactly as the creator set it, as
            // it does without the Advanced Tracking hash.
            if (!redirect || groOwned(redirect)) {
              return;
            }
            // redirectTarget() measured the attribute as it was written,
            // before anything was added to it, and nothing measures it again:
            // a target already near GRO's cap would leave for the next page
            // past it. Measured on a copy, so such a target travels without
            // the id rather than over the cap.
            const next = new URL(redirect.href);
            setParam(next, 'gl_eid', eventId);
            if (next.href.length <= MAX_REDIRECT_LENGTH) {
              redirect.href = next.href;
            }
          });

          if (gate && revealed !== null) {
            quietly(() => open(gate, revealed, true));
          }
          // Any gate still locked, this one included when its answer carried
          // nothing, opens if the subscription made the visitor recognized.
          quietly(unlockAll);

          // Last, so the page's ad network never holds up what the visitor
          // sees, and never for an opt-in the visitor declined.
          quietly(() => {
            if (!declinedOptIn) {
              identify(body, redirect);
            }
          });
        }

        if (redirect) {
          // Busy until the next page replaces this one. Should the browser
          // stay, because the address answered with a download or with no
          // content, the form shows its success message after all, and at
          // once when the browser comes back to it from its back/forward
          // cache (see pageshow below).
          leaving = true;
          const stay = (): void => {
            staying.delete(stay);
            window.clearTimeout(timer);
            form.removeAttribute('aria-busy');
            showSuccess();
          };
          const timer = window.setTimeout(stay, REDIRECT_FALLBACK_MS);
          staying.add(stay);
          window.location.assign(redirect.href);
        }
        return;
      }

      status.textContent = messageFor(response.status, body, messages);
      if (addressRejected(body)) {
        email.setAttribute('aria-invalid', 'true');
      }
      if (body?.code === 'consent_required') {
        consent?.setAttribute('aria-invalid', 'true');
      }
      status.focus();
    } catch {
      status.textContent = messages.generic;
      status.focus();
    } finally {
      if (!leaving) {
        inFlight = false;
        form.removeAttribute('aria-busy');
        submit.textContent = label;
      }
    }
  });

  // Only once the form's own listener is on, so a form whose setup throws
  // (see wire below) is left as the page wrote it. The address this browser
  // used before, or the member's, goes only into a form that posts to the
  // plugin's own submit route: any other action is somewhere a post's author
  // chose.
  if (ownRoute(attr(form, 'action'), SUBMIT_ROUTE)) {
    const stored = readStored();
    if (stored.email) {
      email.value = stored.email;
      if (messages.returning && stored.listed !== false) {
        status.textContent = messages.returning;
      }
    } else if (window.grocersList?.member?.email) {
      email.value = window.grocersList.member.email;
    }
  }
};

// One form never stops the next working: a control named after a method the
// script calls on a form (querySelector, addEventListener…) stands in for
// it, and that form's setup throws. It is left a plain form.
const wire = (form: HTMLFormElement): void => {
  try {
    setup(form);
  } catch {
    // Nothing for the visitor to see.
  }
};

// Back from the next page, a form that left for its redirect shows its
// success message at once: a page in the back/forward cache keeps its timers
// paused (Firefox and Safari do), so its fallback would leave it busy for up
// to four seconds more.
window.addEventListener('pageshow', event => {
  if (event.persisted) {
    staying.forEach(stay => stay());
  }
});

all<HTMLFormElement>('form[data-gl-form]').forEach(wire);
unlockAll();
