// What the form script (view.ts) and the popup (popup.ts) both read off the
// page, read past its markup: a post's author can write a form, and a form or
// a control named after a member of document or of a form stands in for it.

export const STORAGE_KEY = 'grocerslist:form';
// Set beside the httponly cookie the gate route checks, which no script can
// read: only whether asking the route is worth a request.
const HINT_COOKIE = 'gl_forms_subscribed_hint';

// An element's attribute as written. A control named getAttribute would
// stand in for a form's own method (as one named action does for
// form.action).
export const attr = (element: Element, name: string): string | null =>
  Element.prototype.getAttribute.call(element, name);

// document's own members, read past the page's markup: a form's name makes
// the form a property of document that wins over document's own
// (name="querySelectorAll", name="cookie"…), and a post's author can write
// a form.
export const all = <E extends Element>(selector: string): E[] =>
  Array.from(
    Document.prototype.querySelectorAll.call(document, selector)
  ) as E[];

export const create = (tag: string): HTMLElement =>
  Document.prototype.createElement.call(document, tag);

const COOKIE = Object.getOwnPropertyDescriptor(Document.prototype, 'cookie');

export const cookies = (): string =>
  COOKIE && COOKIE.get ? String(COOKIE.get.call(document)) : document.cookie;

export const setCookie = (cookie: string): void => {
  if (COOKIE && COOKIE.set) {
    COOKIE.set.call(document, cookie);
  } else {
    document.cookie = cookie;
  }
};

interface Stored {
  email?: string;
  // false once the address was only used to save a page, opt-in unticked:
  // it is not on the creator's list, so no "already on the list" line.
  listed?: boolean;
}

export const readStored = (): Stored => {
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY);
    const parsed = raw ? (JSON.parse(raw) as Stored) : null;
    return parsed && typeof parsed.email === 'string' ? parsed : {};
  } catch {
    return {};
  }
};

export const hasHint = (): boolean =>
  `; ${cookies()}`.indexOf(`; ${HINT_COOKIE}=`) !== -1;

// The plugin's stylesheets, in the order they print (the popup's and the
// gate's override the form's). Each one's first rule
// sets --gl-<name>-css: 1 on .gl-<name>, a sentinel: a root without it is on
// a page that lost the sheet.
const SHEETS = ['form', 'popup', 'gate'];
// Their roots: a form's wrapper, the popup's <dialog>, a gate's wrapper.
// Stylesheets prints the sheets just before the first one that needs them.
const ROOTS = '.gl-form,.gl-popup,.gl-gate';
// The form's and the popup's second rule sets a second sentinel,
// --gl-<name>-css-states: 1, on a state only this script puts an element
// in: a <form> it marked done, the popup open. No page is served with one,
// so an optimizer that keeps a sheet rule by rule, by what the page's HTML
// uses (FlyingPress, LiteSpeed's UCSS), drops it with the rules for those
// states and keeps the first, on a root the page has. It is read off a
// probe with the class (and the attribute) its rule asks for. The gate's
// one such rule, .gl-gate-start:focus, is not worth loading the file again:
// it has none (docs/FORMS.md).
const STATES: Record<string, string[]> = {
  form: ['gl-form__form gl-form--done'],
  popup: ['gl-popup', 'open'],
};

// The view script's own address as the page asked for it: the plugin's root,
// then blocks/dist/form/view.js, then the query its stylesheets are asked for
// with too (?ver=…).
const VIEW_URL = /^([^?#]*\/)blocks\/dist\/form\/view\.js([?#].*)?$/;

const viewUrl = (script?: HTMLScriptElement | null) =>
  VIEW_URL.exec(script?.src || '');

// Read as the script runs, the only time document.currentScript is this
// script, and through Document.prototype, past a form or an image named
// currentScript. Combined into one file with others by an optimizer, it has
// that file's address, and only a <script> of the plugin's own can say.
const CURRENT_SCRIPT = Object.getOwnPropertyDescriptor(
  Document.prototype,
  'currentScript'
);
const LOADED_FROM =
  viewUrl(CURRENT_SCRIPT?.get?.call(document)) ||
  viewUrl(all<HTMLScriptElement>('script[src*="blocks/dist/form/view.js"]')[0]);

// Whether url is an absolute URL of file, on the page's origin or on the one
// this script came from (a CDN's). FormRenderer prints the URLs, but read
// where it printed none (WordPress 4.4, a page cached before it did),
// window.grocerslistForms goes on up the prototype chain, to an
// Object.prototype a prototype-pollution bug in another of the page's
// scripts lets a URL's query write to; a stylesheet from anywhere else could
// read the page through its selectors.
const ours = (url: string, file: string): boolean => {
  try {
    const { origin, pathname } = new URL(url);
    return (
      pathname.endsWith(`/${file}`) &&
      (origin === window.location.origin ||
        (!!LOADED_FROM && origin === new URL(LOADED_FROM[0]).origin))
    );
  } catch {
    return false;
  }
};

// name's stylesheet: the URL FormRenderer prints beside the REST root, else
// the file beside this script, with the script's own query; null with
// neither. Only a string of the plugin's (ours()) counts: an element whose id
// or name is grocerslistForms (DOM clobbering) is none, nor is a frame of
// that name, which throws when read.
const sheetUrl = (name: string): string | null => {
  const file = `assets/blocks/${name === 'gate' ? 'content-gate' : name}/style.css`;
  try {
    const url = window.grocerslistForms?.css?.[name];
    if (typeof url === 'string' && ours(url, file)) {
      return url;
    }
  } catch {
    // A frame of that name.
  }
  return LOADED_FROM ? `${LOADED_FROM[1]}${file}${LOADED_FROM[2] || ''}` : null;
};

const restored = (name: string): boolean =>
  all(`#gl-${name}-css-restored`).length > 0;

// name's stylesheet as a <link> where Stylesheets prints the sheets: just
// before the page's first root, and the form's before the popup's and the
// gate's, inline or put back, wherever those are. So the popup's and the
// gate's follow the form's, whose rules theirs override, whichever of them
// the page kept. Put in through Element.prototype: the first root can be a
// form a post's author wrote with the class, and a control named before
// would stand in for the form's own.
const restore = (name: string): void => {
  const href = sheetUrl(name);
  const before = all(
    name === 'form' ? `${ROOTS},[id^=gl-popup-css],[id^=gl-gate-css]` : ROOTS
  )[0];
  if (href && before && !restored(name)) {
    const link = create('link') as HTMLLinkElement;
    link.id = `gl-${name}-css-restored`;
    link.rel = 'stylesheet';
    link.href = href;
    Element.prototype.before.call(before, link);
  }
};

const sentinel = (element: Element, property: string): boolean =>
  window.getComputedStyle(element).getPropertyValue(property).trim() === '1';

// Whether the page has all of name's sheet: its first sentinel on root and,
// where it has one, its second on a probe put in root for the read alone,
// hidden (out of the layout and the accessibility tree) and taken out again
// whatever happens. Put in through Element.prototype, as restore() puts a
// <link> in.
const whole = (name: string, root: Element): boolean => {
  if (!sentinel(root, `--gl-${name}-css`)) {
    return false;
  }
  const state = STATES[name];
  if (!state) {
    return true;
  }
  const probe = create('div');
  probe.className = state[0];
  if (state[1]) {
    probe.setAttribute(state[1], '');
  }
  probe.style.display = 'none';
  try {
    Element.prototype.append.call(root, probe);
    return sentinel(probe, `--gl-${name}-css-states`);
  } finally {
    probe.remove();
  }
};

// Puts each stylesheet the page lost back (restore()): one whose root on the
// page lacks its sentinel, because an optimizer the plugin cannot tell took
// the inline copy out, or a render a theme threw away used it up, or lacks
// its second, because an optimizer kept only the rules the page's HTML uses
// (docs/FORMS.md → The stylesheets). One read of each sentinel a run, and
// each sheet once however often it runs; the form's before the popup's and
// the gate's, which bring it unless the page's first form has it. Nothing in
// it throws: it runs on the way to opening the popup.
export const restoreSheets = (): void => {
  try {
    let form = false;
    SHEETS.forEach(name => {
      const root = all(`.gl-${name}`)[0];
      if (!root || !sheetUrl(name) || restored(name)) {
        return;
      }
      const there = whole(name, root);
      if (name === 'form') {
        form = there;
      }
      if (!there) {
        if (name !== 'form' && !form) {
          restore('form');
        }
        restore(name);
      }
    });
  } catch {
    // The page keeps what styles it has.
  }
};
