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
    showIcon?: boolean;
    useBrandColors?: boolean;
    accentColor?: string | null;
    // The checkbox: an optional opt-in on Save to Email, required consent on
    // the other kinds. Ticked to start with only when checked is true: a
    // config cached from a GRO before it had checked leaves it unticked.
    optIn?: { show?: boolean; label?: string; checked?: boolean };
    // Absent from a config cached from a GRO before it had one.
    redirectUrl?: string | null;
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

export const toPreview = (config: FormConfig): Preview => {
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

  return {
    kind: config.kind,
    classes: [
      'gl-form',
      `gl-form--${KIND_SLUGS[config.kind]}`,
      `gl-form--${layout}`,
      `gl-form--${appearance}`,
      showIcon ? 'gl-form--icon' : '',
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
