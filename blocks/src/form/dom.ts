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
