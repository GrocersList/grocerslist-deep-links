// What a gate hides, as GateRegion::mode() in PHP has it: everything below
// it ("below") or only the blocks inside it ("inside"). A gate saved before
// there was a mode hides the blocks inside it when it holds any, and
// everything below it when it holds nothing but empty paragraphs, as a
// freshly inserted gate does.

export type Mode = 'below' | 'inside';

export interface BlockLike {
  name: string;
  attributes: Record<string, unknown>;
}

export const isMode = (value: unknown): value is Mode =>
  value === 'below' || value === 'inside';

// A paragraph's content is a string, or from WordPress 6.5 a RichTextData,
// which gives its HTML as a string.
const html = (value: unknown): string =>
  typeof value === 'string' ? value : value ? String(value) : '';

export const isEmptyParagraph = (block: BlockLike): boolean =>
  block.name === 'core/paragraph' &&
  html(block.attributes.content).replace(
    /<br\s*\/?>|&nbsp;|&#160;|\s/gi,
    ''
  ) === '';

export const effectiveMode = (
  mode: unknown,
  innerBlocks: BlockLike[]
): Mode => {
  if (isMode(mode)) {
    return mode;
  }

  return innerBlocks.some(block => !isEmptyParagraph(block))
    ? 'inside'
    : 'below';
};
