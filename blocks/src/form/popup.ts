// The creator's GRO popup, which PopupRenderer prints hidden in the footer of
// every page its page rules allow: a <dialog> whose data-gl-* attributes say
// who it is for and when it opens. A page cache hands every visitor of a URL
// the same page, so whether this visitor sees it, and when, is decided here
// (docs/FORMS.md → The popup).

import { all, attr, cookies, hasHint, readStored, setCookie } from './dom';

// A visitor who came from one of the creator's GRO emails: GRO knows them.
const KNOWN_COOKIE = 'gl_known';
// The popup this browser closed or signed up through last, kept for its
// "don't show again" days, or for SUBSCRIBED_DAYS after a signup.
const DISMISSED_COOKIE = 'gl_popup_dismissed';
const DAY = 86400;
// How long a popup the visitor signed up through stays away, whoever it is
// for: as long as the subscription's own cookies last (GateCookie).
const SUBSCRIBED_DAYS = 365;
// Opens the popup at once, whoever the visitor is; PopupRenderer prints it
// on every page for this parameter, whatever its page rules say.
const PREVIEW_PARAM = 'gl_popup_preview';
// On <html> while the popup is open: the page does not scroll behind it.
const OPEN = 'gl-popup-open';
// How long a popup the visitor signed up through shows its success message.
const SUCCESS_MS = 2500;

const cookie = (name: string): string | undefined =>
  `; ${cookies()}`.split(`; ${name}=`)[1]?.split(';')[0];

const keep = (name: string, value: string, maxAge: number): void =>
  setCookie(
    `${name}=${value}; path=/; SameSite=Lax` +
      (maxAge > 0 ? `; max-age=${maxAge}` : '') +
      (window.location.protocol === 'https:' ? '; Secure' : '')
  );

// A member signed in to the site, exactly true: an element whose id is
// grocersList is no member (DOM clobbering), nor is a frame of that name,
// which throws when read.
const member = (): boolean => {
  try {
    return window.grocersList?.member?.isWpUser === true;
  } catch {
    return false;
  }
};

// Whether GRO knows this visitor already: they signed up through a GRO form
// in this browser (the content gate's hint, or the address the form script
// remembered, unless only to save a page with the opt-in left unticked), are
// a member signed in to the site, or came from a GRO email.
const known = (): boolean => {
  const stored = readStored();
  return (
    hasHint() ||
    (!!stored.email && stored.listed !== false) ||
    member() ||
    cookie(KNOWN_COOKIE) === '1'
  );
};

export const popup = (): void => {
  const params = new URLSearchParams(window.location.search);
  if (
    params.get('utm_source') === 'gro' &&
    params.get('utm_medium') === 'email'
  ) {
    keep(KNOWN_COOKIE, '1', 365 * DAY);
  }

  const dialog = all<HTMLDialogElement>('dialog[data-gl-popup]')[0];
  // Without the dialog element, the popup is never shown: it stays hidden.
  if (!dialog || typeof dialog.showModal !== 'function') {
    return;
  }

  const hash = (attr(dialog, 'data-gl-popup') || '').split('.')[0];
  const preview = params.get(PREVIEW_PARAM) === '1';
  const number = (name: string): number =>
    Number(attr(dialog, `data-gl-${name}`)) || 0;
  // A popup for people GRO does not know yet, for someone it does; or one
  // this browser closed, or signed up through, lately. A preview shows anyway.
  const unwanted = (): boolean =>
    !preview &&
    ((attr(dialog, 'data-gl-audience') === 'not_subscribed' && known()) ||
      cookie(DISMISSED_COOKIE) === hash);

  if (!hash || unwanted()) {
    return;
  }

  const root = all<HTMLElement>(':root')[0];
  const button = dialog.querySelector<HTMLElement>('.gl-popup__close');
  let shown = false;
  let subscribed = false;
  let pressed: EventTarget | null = null;
  // What <html> had inline as the popup opened, each longhand on its own:
  // the overflow shorthand reads '' while only one is set, as the membership
  // gate's overflow-y is, and putting that back would clear it.
  let overflowX = '';
  let overflowY = '';

  const close = (): void => {
    if (dialog.open) {
      dialog.close();
    }
  };

  // Asked again when it is time: the visitor may have signed up through
  // another form on the page, or closed it in another tab, meanwhile.
  const open = (): void => {
    if (shown || unwanted()) {
      return;
    }
    shown = true;
    // Shown before it opens: a hidden modal would still block the page.
    dialog.removeAttribute('hidden');
    try {
      dialog.showModal();
    } catch {
      dialog.setAttribute('hidden', '');
      return;
    }
    // Inline as well as by the class's rule, which a CSS optimizer that keeps
    // only the rules the served HTML uses leaves out: no page is served with
    // the class on.
    overflowX = root.style.overflowX;
    overflowY = root.style.overflowY;
    root.style.overflowX = root.style.overflowY = 'hidden';
    root.classList.add(OPEN);
    (
      dialog.querySelector<HTMLElement>('input[name="email"]') || button
    )?.focus();
  };

  button?.addEventListener('click', close);
  dialog.addEventListener('cancel', close);
  // A click on the dialog itself is one on the backdrop around the card, but
  // not when it began inside the card: selecting the address, say, and
  // letting go past the card's edge.
  dialog.addEventListener('mousedown', event => {
    pressed = event.target;
  });
  dialog.addEventListener('click', event => {
    if (event.target === dialog && (pressed === null || pressed === dialog)) {
      close();
    }
    pressed = null;
  });
  dialog.addEventListener('close', () => {
    root.classList.remove(OPEN);
    // Only where it is still the popup's own: a lock another script let go
    // of while it was open stays let go.
    if (root.style.overflowX === 'hidden') {
      root.style.overflowX = overflowX;
    }
    if (root.style.overflowY === 'hidden') {
      root.style.overflowY = overflowY;
    }
    // Signed up, the visitor has the signup's cookie already (below); a
    // preview leaves the popup to show as it would.
    if (!subscribed && !preview) {
      keep(DISMISSED_COOKIE, hash, number('dismiss-days') * DAY);
    }
  });
  // The form's own event, after its success message. Signed up, the visitor
  // never sees this popup again, an `everyone` one included: the cookie is
  // set now, not when the popup closes, since a form leaving for its
  // redirect takes the page with it first.
  dialog.addEventListener('grocerslist:subscribed', event => {
    subscribed = true;
    if (!preview) {
      keep(DISMISSED_COOKIE, hash, SUBSCRIBED_DAYS * DAY);
    }
    if (attr(event.target as Element, 'data-gl-redirect') === null) {
      window.setTimeout(close, SUCCESS_MS);
    }
  });

  if (preview) {
    open();
  } else if (attr(dialog, 'data-gl-trigger') === 'scroll') {
    // As the membership gate's scroll trigger counts it (the widget's
    // useGateTrigger): how far down the visitor has scrolled, of all there is
    // to scroll, so 50 is halfway down and 100 the bottom, with a pixel's
    // slack for a zoomed page that stops a fraction short of it. A page with
    // nothing to scroll opens it at once.
    const reached = (): void => {
      const scrollable = root.scrollHeight - window.innerHeight;
      if (
        scrollable <= 0 ||
        window.scrollY + 1 >= (scrollable * number('scroll')) / 100
      ) {
        window.removeEventListener('scroll', reached);
        open();
      }
    };
    window.addEventListener('scroll', reached, { passive: true });
    reached();
  } else {
    window.setTimeout(open, number('delay') * 1000);
  }
};
