import { __ } from '@wordpress/i18n';

// GL_ListGrowthFormConfig, as GET /grocerslist/v1/forms/<hash> passes it on
// from GRO. The rules below mirror FormRenderer.php, so the editor preview
// renders what the front end does.

export type Kind = 'SIGNUP' | 'SAVE_TO_EMAIL' | 'CONTENT_GATE';

export interface FormConfig {
  hash: string;
  kind: Kind;
  name: string;
  isDefault: boolean;
  design?: {
    heading?: string;
    description?: string;
    emailPlaceholder?: string;
    namePlaceholder?: string;
    buttonLabel?: string;
    successMessage?: string;
    collectFirstName?: boolean;
    layout?: string;
    appearance?: string;
    // Sent only for "sharp": without it, or with another value, rounded.
    corners?: string;
    showIcon?: boolean;
    useBrandColors?: boolean;
    accentColor?: string | null;
    // The checkbox: an optional opt-in on Save to Email, required consent on
    // the other kinds. Ticked to start with only when checked is true: a
    // config cached from a GRO before it had checked leaves it unticked.
    optIn?: { show?: boolean; label?: string; checked?: boolean };
    // Absent from a config cached from a GRO before it had one.
    redirectUrl?: string | null;
    // Sent only for a form with a picture: PROFILE_PHOTO or LOGO, and the
    // URL GRO resolved for it (null when the creator has none).
    image?: string;
    imageUrl?: string | null;
    // Sent only for a form with a banner.
    bannerUrl?: string;
  };
  brand?: {
    accent?: string;
    background?: string;
    text?: string;
    buttonText?: string | null;
  };
  // Null for Save to Email forms and content gates, which have no landing page.
  landingUrl?: string | null;
}

export interface FormSummary {
  hash: string;
  kind: Kind;
  name: string;
  isDefault: boolean;
}

export interface Preview {
  kind: Kind;
  classes: string;
  style: Record<string, string> | undefined;
  heading: string;
  description: string;
  emailPlaceholder: string;
  namePlaceholder: string;
  buttonLabel: string;
  collectFirstName: boolean;
  compact: boolean;
  showIcon: boolean;
  // Square corners (drawsSharp()): gl-form--sharp on the form, and the
  // content gate's card's gl-gate__card--sharp.
  sharp: boolean;
  // The banner's URL ('' for none), and the picture's modifier
  // (gl-form--image-<slug>) and URL.
  banner: string;
  picture: { slug: string; url: string } | null;
  optIn: { label: string; checked: boolean } | null;
}

const KIND_SLUGS: Record<Kind, string> = {
  SIGNUP: 'signup',
  SAVE_TO_EMAIL: 'save-to-email',
  CONTENT_GATE: 'content-gate',
};

const LAYOUTS = ['stacked', 'inline', 'split', 'compact'];
const APPEARANCES = ['boxed', 'plain'];

const HEX_COLOR = /^#?(?:[0-9a-f]{3}){1,2}$/i;

export const kindLabel = (kind: Kind): string => {
  switch (kind) {
    case 'SAVE_TO_EMAIL':
      return __('Save to Email', 'grocers-list');
    case 'CONTENT_GATE':
      return __('Content gate', 'grocers-list');
    default:
      return __('Signup', 'grocers-list');
  }
};

const hexColor = (value: unknown): string =>
  typeof value === 'string' && HEX_COLOR.test(value.trim())
    ? `#${value.trim().replace(/^#/, '').toLowerCase()}`
    : '';

// Black or white, whichever has more contrast on the colour.
const textOn = (hex: string): string => {
  const digits = hex.slice(1);
  const pairs =
    digits.length === 3
      ? digits.split('').map(digit => digit + digit)
      : [digits.slice(0, 2), digits.slice(2, 4), digits.slice(4, 6)];
  const [r, g, b] = pairs.map(pair => {
    const value = parseInt(pair, 16) / 255;
    return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
  });
  const luminance = 0.2126 * r + 0.7152 * g + 0.0722 * b;

  return 1.05 / (luminance + 0.05) >= (luminance + 0.05) / 0.05
    ? '#fff'
    : '#000';
};

const text = (value: unknown, fallback: string): string =>
  typeof value === 'string' && value.trim() !== '' ? value.trim() : fallback;

// FormRenderer::drawsSharp(), given the form's appearance as read (its
// kind's default for none): "sharp" on a form with a box to square, a boxed
// one or a popup's or a content gate's, whose cards are always drawn. A
// popup's kind is no Kind here, as the editor never previews one, but the
// rule is the renderer's whole.
export const drawsSharp = (
  corners: unknown,
  appearance: string,
  kind: Kind | 'POPUP'
): boolean =>
  corners === 'sharp' &&
  (appearance === 'boxed' || kind === 'POPUP' || kind === 'CONTENT_GATE');

// FormRenderer::IMAGE_SLUGS.
const imageSlug = (image: unknown): string => {
  switch (image) {
    case 'PROFILE_PHOTO':
      return 'avatar';
    case 'LOGO':
      return 'logo';
    default:
      return '';
  }
};

// What PHP's trim() takes off. String.prototype.trim() takes more (a
// no-break space, a byte order mark), which the renderer keeps, and refuses.
const PHP_TRIM = /^[ \t\n\r\0\v]+|[ \t\n\r\0\v]+$/g;

// FormRenderer::imageUrl(), for a banner and a picture alike: trimmed as PHP
// trims, when it is an http(s) URL with a host. The scheme and its two
// slashes come first, then the host, as wp_parse_url() reads one: the URL
// parser alone drops a tab or a newline anywhere, reads a backslash as a
// slash, and finds a host in https:host and https:///host, which the
// renderer shows no image for.
const imageUrl = (value: unknown): string => {
  const url = typeof value === 'string' ? value.replace(PHP_TRIM, '') : '';
  if (!/^https?:\/\/[^/]/i.test(url)) {
    return '';
  }

  try {
    return new URL(url).hostname !== '' ? url : '';
  } catch {
    return '';
  }
};

// FormRenderer::picture().
const pictureOf = (
  design: NonNullable<FormConfig['design']>
): Preview['picture'] => {
  const slug = imageSlug(design.image);
  const url = slug ? imageUrl(design.imageUrl) : '';

  return url ? { slug, url } : null;
};

const brandStyle = (config: FormConfig): Record<string, string> | undefined => {
  const design = config.design || {};
  const brand = config.brand || {};
  if (design.useBrandColors === false) {
    return undefined;
  }

  const ownAccent = hexColor(design.accentColor);
  const accent = ownAccent || hexColor(brand.accent);
  const buttonText = ownAccent ? '' : hexColor(brand.buttonText);
  const colors: Record<string, string> = {
    '--gl-form-accent': accent,
    '--gl-form-accent-text': buttonText || (accent ? textOn(accent) : ''),
    '--gl-form-background': hexColor(brand.background),
    '--gl-form-text': hexColor(brand.text),
  };
  Object.keys(colors).forEach(key => {
    if (!colors[key]) {
      delete colors[key];
    }
  });

  return Object.keys(colors).length ? colors : undefined;
};

// inGate: the form is in a content gate, which shows no banner or picture,
// whichever form it holds (FormRenderer::render()); nor does a content
// gate's own form, wherever it shows (FormRenderer::normalize()).
export const toPreview = (config: FormConfig, inGate = false): Preview => {
  const design = config.design || {};
  const saveToEmail = config.kind === 'SAVE_TO_EMAIL';
  const gate = config.kind === 'CONTENT_GATE';
  const layout =
    design.layout && LAYOUTS.includes(design.layout)
      ? design.layout
      : saveToEmail || gate
        ? 'stacked'
        : 'inline';
  const appearance =
    design.appearance && APPEARANCES.includes(design.appearance)
      ? design.appearance
      : saveToEmail || gate
        ? 'boxed'
        : 'plain';
  const style = brandStyle(config);
  const showIcon = Boolean(design.showIcon);
  const sharp = drawsSharp(design.corners, appearance, config.kind);
  const header = !inGate && !gate;
  const banner = header ? imageUrl(design.bannerUrl) : '';
  const picture = header ? pictureOf(design) : null;

  return {
    kind: config.kind,
    classes: [
      'gl-form',
      `gl-form--${KIND_SLUGS[config.kind]}`,
      `gl-form--${layout}`,
      `gl-form--${appearance}`,
      sharp ? 'gl-form--sharp' : '',
      showIcon ? 'gl-form--icon' : '',
      banner ? 'gl-form--banner' : '',
      picture ? `gl-form--image-${picture.slug}` : '',
      style ? 'gl-form--brand' : '',
    ]
      .filter(Boolean)
      .join(' '),
    style,
    heading: text(design.heading, ''),
    description: text(design.description, ''),
    emailPlaceholder: text(
      design.emailPlaceholder,
      __('Your email', 'grocers-list')
    ),
    namePlaceholder: text(
      design.namePlaceholder,
      __('First name', 'grocers-list')
    ),
    buttonLabel: text(
      design.buttonLabel,
      saveToEmail
        ? __('Send Recipe', 'grocers-list')
        : gate
          ? __('Keep reading', 'grocers-list')
          : __('Subscribe', 'grocers-list')
    ),
    collectFirstName: Boolean(design.collectFirstName),
    compact: layout === 'compact',
    showIcon,
    sharp,
    banner,
    picture,
    optIn: design.optIn?.show
      ? {
          label: text(
            design.optIn.label,
            saveToEmail
              ? __('Also send me new recipes and updates', 'grocers-list')
              : __(
                  'I agree to receive emails and can unsubscribe at any time.',
                  'grocers-list'
                )
          ),
          checked: Boolean(design.optIn.checked),
        }
      : null,
  };
};
