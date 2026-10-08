import { createElement, Fragment } from '@wordpress/element';

import type { FormConfig, Kind } from '../form/config';
import { toPreview } from '../form/config';

// The same shapes FormRenderer.php draws: an envelope, a heart and a lock.
const ICON_SHAPES: Record<Kind, JSX.Element> = {
  SIGNUP: (
    <>
      <rect x="3" y="5" width="18" height="14" rx="2" />
      <path d="M3.5 6.5 12 13l8.5-6.5" />
    </>
  ),
  SAVE_TO_EMAIL: (
    <path d="M12 20.5C12 20.5 3 15 3 9.25C3 6.35 5.25 4.5 7.75 4.5C9.6 4.5 11.1 5.55 12 7.1C12.9 5.55 14.4 4.5 16.25 4.5C18.75 4.5 21 6.35 21 9.25C21 15 12 20.5 12 20.5Z" />
  ),
  CONTENT_GATE: (
    <>
      <rect x="5" y="11" width="14" height="10" rx="2" />
      <path d="M8 11V8a4 4 0 0 1 8 0v3" />
    </>
  ),
};

export const Icon = ({
  kind,
  className = 'gl-form__icon',
}: {
  kind: Kind;
  className?: string;
}) => (
  <svg
    className={className || undefined}
    viewBox="0 0 24 24"
    width="24"
    height="24"
    aria-hidden="true"
    focusable="false"
    fill="none"
    stroke="currentColor"
    strokeWidth="2"
    strokeLinecap="round"
    strokeLinejoin="round"
  >
    {ICON_SHAPES[kind]}
  </svg>
);

// The form with the front end's markup, inside the wrapper that carries
// toPreview()'s classes and colours: its inputs disabled, its button not one.
export const FormPreview = ({ config }: { config: FormConfig }) => {
  const preview = toPreview(config);
  const title = preview.heading !== '' || preview.showIcon;
  const description = !preview.compact && preview.description !== '';

  return (
    <div className="gl-form__form">
      {(title || description) && (
        <div className="gl-form__copy">
          {title && (
            <p className="gl-form__title">
              {preview.showIcon && <Icon kind={preview.kind} />}
              {preview.heading !== '' && (
                <span className="gl-form__title-text">{preview.heading}</span>
              )}
            </p>
          )}
          {description && (
            <p className="gl-form__description">{preview.description}</p>
          )}
        </div>
      )}
      <div className="gl-form__fields">
        <div className="gl-form__row">
          {preview.collectFirstName && (
            <label className="gl-form__field gl-form__field--name">
              <input
                type="text"
                placeholder={preview.namePlaceholder}
                aria-label={preview.namePlaceholder}
                disabled
              />
            </label>
          )}
          <label className="gl-form__field gl-form__field--email">
            <input
              type="email"
              placeholder={preview.emailPlaceholder}
              aria-label={preview.emailPlaceholder}
              disabled
            />
          </label>
          <span className="gl-form__submit wp-block-button__link wp-element-button">
            {preview.buttonLabel}
          </span>
        </div>
        {preview.optIn !== null && (
          <label className="gl-form__optin">
            <input
              type="checkbox"
              defaultChecked={preview.optIn.checked}
              disabled
            />
            <span>{preview.optIn.label}</span>
          </label>
        )}
      </div>
    </div>
  );
};
