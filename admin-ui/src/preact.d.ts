// This file provides type compatibility between React and Preact
import * as preact from 'preact';

declare global {
  namespace JSX {
    interface IntrinsicElements extends preact.JSX.IntrinsicElements {}
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