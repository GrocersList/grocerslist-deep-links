import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import type { BlockEditProps } from '@wordpress/blocks';
import { getCategories, registerBlockType } from '@wordpress/blocks';
import { PanelBody, Placeholder, Spinner } from '@wordpress/components';
import { createElement, Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import {
  FormPicker,
  MissingFormNotice,
  NoFormsNotice,
  ProblemNotice,
} from '../shared/FormPicker';
import { FormPreview } from '../shared/FormPreview';
import { useFormConfig } from '../shared/useFormConfig';
import { useDefaultSignupForm, useGroForms } from '../shared/useGroForms';

import { toPreview } from './config';

const BLOCK_NAME = 'grocerslist/form';

interface Attributes {
  [key: string]: unknown;
  formId: string;
}

const Edit = ({ attributes, setAttributes }: BlockEditProps<Attributes>) => {
  const { formId } = attributes;
  const list = useGroForms();
  const config = useFormConfig(formId);

  useDefaultSignupForm(formId, list, setAttributes);

  const preview = config ? toPreview(config) : null;
  const blockProps = useBlockProps(
    preview ? { className: preview.classes, style: preview.style } : {}
  );

  const connected = list === null || list.connected;
  const forms = list ? list.forms : [];

  const picker = (
    <FormPicker
      formId={formId}
      list={list}
      onChange={value => setAttributes({ formId: value })}
    />
  );

  const problemNotice = <ProblemNotice problem={list?.problem || null} />;
  const noForms = <NoFormsNotice />;
  const missing = <MissingFormNotice />;

  let canvas: JSX.Element;
  if (list === null || (formId && config === undefined)) {
    canvas = (
      <Placeholder icon="email-alt" label={__('GRO Form', 'grocers-list')}>
        <Spinner />
      </Placeholder>
    );
  } else if (!connected) {
    canvas = (
      <Placeholder icon="email-alt" label={__('GRO Form', 'grocers-list')}>
        {problemNotice}
      </Placeholder>
    );
  } else if (config) {
    canvas = <FormPreview config={config} />;
  } else {
    canvas = (
      <Placeholder
        icon="email-alt"
        label={__('GRO Form', 'grocers-list')}
        instructions={__(
          'Pick one of your GRO forms. Its copy, layout and colours are edited in GRO.',
          'grocers-list'
        )}
      >
        {forms.length === 0 ? noForms : formId ? missing : null}
        {forms.length > 0 && picker}
      </Placeholder>
    );
  }

  return (
    <>
      <InspectorControls>
        <PanelBody title={__('Form', 'grocers-list')}>
          {!connected && problemNotice}
          {connected && list !== null && forms.length === 0 && noForms}
          {connected && formId && config === null && missing}
          {picker}
          <p>
            {__(
              'The form’s copy, layout, colours and tags are edited in GRO; changes show here within a minute.',
              'grocers-list'
            )}
          </p>
        </PanelBody>
      </InspectorControls>
      <div {...blockProps}>{canvas}</div>
    </>
  );
};

// Everything else — title, description, attributes, supports — comes from the
// server-registered block.json, so these settings are deliberately partial.
registerBlockType(BLOCK_NAME, {
  category: getCategories().some(category => category.slug === 'grocerslist')
    ? 'grocerslist'
    : 'widgets',
  edit: Edit,
  save: () => null,
} as unknown as Parameters<typeof registerBlockType>[1]);
