import {
  InnerBlocks,
  InspectorControls,
  useBlockProps,
} from '@wordpress/block-editor';
import type { BlockEditProps } from '@wordpress/blocks';
import { getCategories, registerBlockType } from '@wordpress/blocks';
import {
  Notice,
  PanelBody,
  Placeholder,
  RadioControl,
  Spinner,
  TextControl,
  ToggleControl,
} from '@wordpress/components';
import { useDispatch, useRegistry, useSelect } from '@wordpress/data';
import { createElement, Fragment, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { toPreview } from '../form/config';
import {
  FormPicker,
  MissingFormNotice,
  NoFormsNotice,
  ProblemNotice,
} from '../shared/FormPicker';
import { FormPreview, Icon } from '../shared/FormPreview';
import { useFormConfig } from '../shared/useFormConfig';
import {
  defaultGateForm,
  useDefaultGateForm,
  useGroForms,
} from '../shared/useGroForms';

import type { BlockLike, Mode } from './mode';
import { effectiveMode, isEmptyParagraph, isMode } from './mode';

const BLOCK_NAME = 'grocerslist/content-gate';
const BLOCK_EDITOR = 'core/block-editor';

// block.json's teaser default, which the site shows in its own language.
const DEFAULT_TEASER = 'The rest of this post is one click away.';

// Content that never is a post's own, so a gate at its top still is not at
// the top of a post: a synced pattern, a template, a template part.
const NOT_POST_CONTENT = ['wp_block', 'wp_template', 'wp_template_part'];

const TEMPLATE: Array<[string, Record<string, unknown>]> = [
  [
    'core/paragraph',
    { placeholder: __('Write what subscribers unlock…', 'grocers-list') },
  ],
];

interface Attributes {
  [key: string]: unknown;
  formId: string;
  gateId: string;
  teaser: string;
  mode?: Mode;
  hideRecipeMetadata: boolean;
}

interface EditorBlock extends BlockLike {
  clientId: string;
}

interface BlockEditorSelectors {
  getBlocks: (rootClientId: string) => EditorBlock[];
  getBlockRootClientId: (clientId: string) => string | null;
  getBlockName: (clientId: string) => string | null;
  getBlockIndex: (clientId: string, rootClientId?: string) => number;
}

interface BlockEditorActions {
  moveBlocksToPosition: (
    clientIds: string[],
    fromRootClientId: string,
    toRootClientId: string,
    index: number
  ) => void;
  removeBlocks: (clientIds: string[], selectPrevious?: boolean) => void;
  __unstableMarkNextChangeAsNotPersistent?: () => void;
}

// Which block holds each gate id in this editor. The gate route finds a
// gate's content by its id, so a duplicated gate, which starts with its
// original's, takes a new one of its own.
const holders = new Map<string, string>();

const Edit = ({
  attributes,
  setAttributes,
  clientId,
}: BlockEditProps<Attributes>) => {
  const { formId, gateId, teaser, mode, hideRecipeMetadata } = attributes;
  const list = useGroForms();
  const config = useFormConfig(formId);
  const registry = useRegistry();
  const actions = useDispatch(BLOCK_EDITOR) as BlockEditorActions;

  // Only a gate at the top of a post's content can hide everything below
  // it; anywhere else it hides the blocks inside it (GateRegion.php).
  const { nested, innerBlocks } = useSelect(
    select => {
      const editor = select(BLOCK_EDITOR) as BlockEditorSelectors;
      const root = editor.getBlockRootClientId(clientId);
      const postType = (
        select('core/editor') as
          | { getCurrentPostType?: () => string | null }
          | undefined
      )?.getCurrentPostType?.();

      return {
        nested:
          (!!root && editor.getBlockName(root) !== 'core/post-content') ||
          (!!postType && NOT_POST_CONTENT.includes(postType)),
        innerBlocks: editor.getBlocks(clientId),
      };
    },
    [clientId]
  );

  useDefaultGateForm(formId, list, setAttributes);

  useEffect(() => {
    const holder = gateId ? holders.get(gateId) : undefined;
    if (gateId && (!holder || holder === clientId)) {
      holders.set(gateId, clientId);
      return;
    }

    holders.set(clientId, clientId);
    setAttributes({ gateId: clientId });
  }, [gateId, clientId, setAttributes]);

  // A gate just inserted: at the top of a post it hides everything below
  // it, anywhere else the blocks inside it. Saved with the gate, so the
  // server never has to guess. (A gate saved before there was a mode gets
  // its own when the post is opened: see LEGACY below.)
  useEffect(() => {
    if (isMode(mode)) {
      return;
    }

    actions.__unstableMarkNextChangeAsNotPersistent?.();
    setAttributes({
      mode: nested ? 'inside' : effectiveMode(undefined, innerBlocks),
    });
  }, [mode, nested, innerBlocks, actions, setAttributes]);

  const chosen: Mode = isMode(mode)
    ? mode
    : nested
      ? 'inside'
      : effectiveMode(undefined, innerBlocks);
  const acting: Mode = nested ? 'inside' : chosen;

  // Everything below: the blocks inside go directly after the gate, where
  // they stay hidden, and the empty placeholder goes.
  const hideEverythingBelow = () => {
    const editor = registry.select(
      BLOCK_EDITOR
    ) as unknown as BlockEditorSelectors;
    const inner = editor.getBlocks(clientId);
    const root = editor.getBlockRootClientId(clientId) || '';
    const moved = inner.filter(block => !isEmptyParagraph(block));
    const empty = inner.filter(block => isEmptyParagraph(block));

    if (moved.length) {
      actions.moveBlocksToPosition(
        moved.map(block => block.clientId),
        clientId,
        root,
        editor.getBlockIndex(clientId, root) + 1
      );
    }
    if (empty.length) {
      actions.removeBlocks(
        empty.map(block => block.clientId),
        false
      );
    }
    setAttributes({ mode: 'below' });
  };

  const onModeChange = (value: string) => {
    if (value === 'below') {
      hideEverythingBelow();
    } else if (value === 'inside') {
      setAttributes({ mode: 'inside' });
    }
  };

  const blockProps = useBlockProps({
    className: `gl-gate-editor gl-gate-editor--${acting}`,
  });

  const connected = list === null || list.connected;
  const forms = list ? list.forms : [];
  // Also while useDefaultGateForm() is about to pick the form: no picker
  // flashes by first.
  const loading =
    list === null ||
    (formId !== '' && config === undefined) ||
    (formId === '' && defaultGateForm(forms) !== undefined);
  const lock = <Icon kind="CONTENT_GATE" className="gl-gate-editor__icon" />;
  const preview = config ? toPreview(config) : null;
  const teaserText =
    teaser === DEFAULT_TEASER
      ? __('The rest of this post is one click away.', 'grocers-list')
      : teaser;

  const picker = (
    <FormPicker
      formId={formId}
      list={list}
      onChange={value => setAttributes({ formId: value })}
    />
  );
  const problemNotice = <ProblemNotice problem={list?.problem || null} />;

  const nestedNotice =
    nested && mode === 'below' ? (
      <Notice
        status="warning"
        isDismissible={false}
        actions={[
          {
            label: __('Hide only the blocks inside', 'grocers-list'),
            onClick: () => setAttributes({ mode: 'inside' }),
          },
        ]}
      >
        {__(
          'This gate sits inside another block, so it hides only the blocks inside it, not everything below.',
          'grocers-list'
        )}
      </Notice>
    ) : null;

  let card: JSX.Element;
  if (config && preview) {
    card = (
      <div className="gl-gate__card" style={preview.style}>
        <span className="gl-gate__badge">
          <Icon kind="CONTENT_GATE" className="" />
        </span>
        {teaserText.trim() !== '' && (
          <p className="gl-gate__teaser">{teaserText}</p>
        )}
        <div className={preview.classes} style={preview.style}>
          <FormPreview config={config} />
        </div>
      </div>
    );
  } else if (loading) {
    card = (
      <Placeholder icon={lock} label={__('GRO Content Gate', 'grocers-list')}>
        <Spinner />
      </Placeholder>
    );
  } else if (!connected) {
    card = (
      <Placeholder icon={lock} label={__('GRO Content Gate', 'grocers-list')}>
        {problemNotice}
      </Placeholder>
    );
  } else {
    card = (
      <Placeholder
        icon={lock}
        label={__('GRO Content Gate', 'grocers-list')}
        instructions={__(
          'Pick the GRO form visitors fill in to see what this gate hides. Its copy, layout and colours are edited in GRO.',
          'grocers-list'
        )}
      >
        {forms.length === 0 ? (
          <NoFormsNotice />
        ) : formId ? (
          <MissingFormNotice />
        ) : null}
        {forms.length > 0 && picker}
      </Placeholder>
    );
  }

  return (
    <>
      <InspectorControls>
        <PanelBody title={__('Gate', 'grocers-list')}>
          {!connected && problemNotice}
          {connected && list !== null && forms.length === 0 && (
            <NoFormsNotice />
          )}
          {connected && formId !== '' && config === null && (
            <MissingFormNotice />
          )}
          {picker}
          {nested ? (
            <>
              {nestedNotice}
              <p>
                {__(
                  'Inside another block, a gate hides only the blocks inside it.',
                  'grocers-list'
                )}
              </p>
            </>
          ) : (
            <RadioControl
              label={__('What it hides', 'grocers-list')}
              selected={chosen}
              options={[
                {
                  label: __('Everything below this block', 'grocers-list'),
                  value: 'below',
                },
                {
                  label: __('Only the blocks inside this gate', 'grocers-list'),
                  value: 'inside',
                },
              ]}
              onChange={onModeChange}
            />
          )}
          <TextControl
            label={__('Teaser', 'grocers-list')}
            help={__(
              'Shown above the form. Leave it empty for none.',
              'grocers-list'
            )}
            value={teaser}
            onChange={value => setAttributes({ teaser: value })}
            __next40pxDefaultSize
            __nextHasNoMarginBottom
          />
          <ToggleControl
            label={__(
              "Leave the ingredients and steps out of this page's recipe data",
              'grocers-list'
            )}
            help={__(
              'WP Recipe Maker publishes a recipe’s ingredients and steps in the page’s recipe data, where anyone can read them while the gate is locked. Turn this on and they are left out. The recipe keeps its name, picture, summary, rating, times and nutrition facts, so it can still show as a recipe in search results, but Google no longer has its ingredients or steps, and Search Console reports them as missing recommended fields. It applies only where this gate hides a WP Recipe Maker recipe, and only on this post. After turning it on, clear your page cache and CDN for this post.',
              'grocers-list'
            )}
            checked={hideRecipeMetadata === true}
            onChange={value => setAttributes({ hideRecipeMetadata: value })}
            __nextHasNoMarginBottom
          />
          <p>
            {__(
              'Visitors see a blurred glimpse of what the gate hides, under a card with the teaser and the form. Once they subscribe, and for anyone signed in who can edit posts, the content shows in its place.',
              'grocers-list'
            )}
          </p>
        </PanelBody>
      </InspectorControls>
      <div {...blockProps}>
        <p className="gl-gate-editor__label">
          {lock}
          <span>
            {acting === 'below'
              ? __(
                  'Everything below this point is hidden from visitors until they subscribe.',
                  'grocers-list'
                )
              : __(
                  'The blocks inside this gate are hidden from visitors until they subscribe.',
                  'grocers-list'
                )}
            {config && preview
              ? ` ${__('They see this instead:', 'grocers-list')}`
              : ''}
          </span>
        </p>
        {nestedNotice}
        {card}
        {acting === 'inside' && (
          <div className="gl-gate-editor__inner">
            <p className="gl-gate-editor__label">
              {__('Hidden until they subscribe:', 'grocers-list')}
            </p>
            <InnerBlocks template={TEMPLATE} />
          </div>
        )}
      </div>
    </>
  );
};

// A gate saved before there was a mode (see mode.ts) gets the one it has
// always had when the post is opened, and keeps it once the post is saved.
// Its save is the same as today's; only its attributes predate "mode".
const LEGACY = {
  attributes: {
    formId: { type: 'string', default: '' },
    gateId: { type: 'string', default: '' },
    teaser: { type: 'string', default: DEFAULT_TEASER },
    // Declared here too, so Gutenberg's re-parse against this deprecation's
    // own schema does not drop a gate that carries the switch and no mode,
    // which migrate() would then save back off.
    hideRecipeMetadata: { type: 'boolean', default: false },
  },
  supports: { html: false, anchor: true },
  isEligible: (attributes: Record<string, unknown>) => !isMode(attributes.mode),
  migrate: (attributes: Record<string, unknown>, innerBlocks: BlockLike[]) => {
    const legacy = effectiveMode(undefined, innerBlocks);

    // Hiding everything below, it holds nothing but empty paragraphs.
    return [
      { ...attributes, mode: legacy },
      legacy === 'below' ? [] : innerBlocks,
    ];
  },
  save: () => <InnerBlocks.Content />,
};

// Everything else — title, description, attributes, supports — comes from the
// server-registered block.json, so these settings are deliberately partial.
// The inner blocks are saved into the post; the server decides who sees them.
registerBlockType(BLOCK_NAME, {
  category: getCategories().some(category => category.slug === 'grocerslist')
    ? 'grocerslist'
    : 'widgets',
  edit: Edit,
  save: () => <InnerBlocks.Content />,
  deprecated: [LEGACY],
} as unknown as Parameters<typeof registerBlockType>[1]);
