// @wordpress/block-editor ships no type declarations; this covers the handful
// of exports the blocks use.
declare module '@wordpress/block-editor' {
  import type { ComponentType, ReactNode } from 'react';

  export function useBlockProps(
    props?: Record<string, unknown>
  ): Record<string, unknown>;

  export const InspectorControls: ComponentType<{ children?: ReactNode }>;

  export const InnerBlocks: ComponentType<{
    template?: Array<[string, Record<string, unknown>?]>;
    templateLock?: 'all' | 'insert' | 'contentOnly' | false;
  }> & { Content: ComponentType };
}
