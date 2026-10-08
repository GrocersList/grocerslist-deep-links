import { Notice, SelectControl } from '@wordpress/components';
import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { kindLabel } from '../form/config';

import type { FormList, Problem } from './useGroForms';

const SETTINGS_URL = 'admin.php?page=grocers-list';

// The creator's forms, as "<name> — <kind>". No empty choice once a form is
// set: a block always shows one.
export const FormPicker = ({
  formId,
  list,
  onChange,
}: {
  formId: string;
  list: FormList | null;
  onChange: (hash: string) => void;
}) => {
  const connected = list === null || list.connected;
  const forms = list ? list.forms : [];
  const listed = forms.some(form => form.hash === formId);
  const options = [
    ...(formId
      ? []
      : [{ label: __('Choose a form…', 'grocers-list'), value: '' }]),
    ...forms.map(form => ({
      label: `${form.name || form.hash} — ${kindLabel(form.kind)}`,
      value: form.hash,
    })),
    ...(formId && list && !listed
      ? [
          {
            label: __('A form GRO no longer has', 'grocers-list'),
            value: formId,
            disabled: true,
          },
        ]
      : []),
  ];

  return (
    <SelectControl
      label={__('GRO form', 'grocers-list')}
      value={formId}
      options={options}
      onChange={onChange}
      disabled={!list || !connected}
      __next40pxDefaultSize
      __nextHasNoMarginBottom
    />
  );
};

// What the editor says when there is no list to pick from.
export const ProblemNotice = ({ problem }: { problem: Problem | null }) => {
  if (problem === 'unavailable') {
    return (
      <Notice status="info" isDismissible={false}>
        {__('GRO Forms isn’t available on this account yet.', 'grocers-list')}
      </Notice>
    );
  }

  if (problem === 'unreachable') {
    return (
      <Notice status="warning" isDismissible={false}>
        {__(
          'GRO can’t be reached right now. Try again in a minute.',
          'grocers-list'
        )}
      </Notice>
    );
  }

  return (
    <Notice status="warning" isDismissible={false}>
      {__('Connect this site to GRO to show your GRO forms.', 'grocers-list')}{' '}
      <a href={SETTINGS_URL}>{__('Open GRO settings', 'grocers-list')}</a>
    </Notice>
  );
};

export const NoFormsNotice = () => (
  <Notice status="info" isDismissible={false}>
    {__('Create a form in GRO → Emails → Forms.', 'grocers-list')}
  </Notice>
);

export const MissingFormNotice = () => (
  <Notice status="warning" isDismissible={false}>
    {__(
      'This form no longer exists in GRO — pick another one.',
      'grocers-list'
    )}
  </Notice>
);
