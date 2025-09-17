// This file provides type compatibility between React and Preact
import * as preact from 'preact';

declare global {
  namespace JSX {
    type IntrinsicElements = preact.JSX.IntrinsicElements;
  }
}

declare module 'react' {
  export = preact;
}

declare module 'react-dom' {
  export = preact;
}

declare module 'react-dom/client' {
  import * as reactDomClient from 'preact/compat/client';
  export = reactDomClient;
}

// Additional JSX runtime declarations for completeness
declare module 'react/jsx-runtime' {
  export * from 'preact/jsx-runtime';
}

declare module 'react/jsx-dev-runtime' {
  export * from 'preact/jsx-dev-runtime';
}
