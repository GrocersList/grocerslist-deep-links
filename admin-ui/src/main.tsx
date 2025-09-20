import ReactDOM from 'react-dom/client';

import createCache from '@emotion/cache';
import { CacheProvider } from '@emotion/react';

import type {
  ICreatorProvisioningSettings,
  ICreatorSettings,
} from './contexts/SetupContext.tsx';

import { App } from './App.tsx';

declare global {
  interface Window {
    grocersList: {
      ajaxUrl: string;
      settings: ICreatorSettings;
      provisioning: ICreatorProvisioningSettings;
      nonces: Record<string, string>;
    };
    wp: {
      data: {
        select: (store: string) => {
          getEditedPostAttribute: (attribute: string) => any;
        };
        dispatch: (store: string) => {
          editPost: (data: { meta: Record<string, any> }) => void;
        };
      };
    };
  }
}

const root = document.getElementById('root')!;
const shadowRoot = root.attachShadow({ mode: 'open' });

const link = document.createElement('link');
link.setAttribute('rel', 'stylesheet');
link.setAttribute('href', '/style.css');
shadowRoot.appendChild(link);

const style = document.createElement('style');
style.textContent = `
  :host {
    all: initial;
    font-family: system-ui, Avenir, Helvetica, Arial, sans-serif;
    background: transparent;
    color: inherit; 
    min-width: 320px;
    min-height: 100vh;
    display: block;
  }

  *, *::before, *::after {
    box-sizing: border-box;
  }

  button {
    border-radius: 8px;
    border: 1px solid transparent;
    padding: 0.6em 1.2em;
    font-size: 1em;
    font-family: inherit;
    background-color: #f9f9f9;
    cursor: pointer;
    transition: border-color 0.25s;
  }

  button:hover {
    border-color: #646cff;
  }

  button:focus,
  button:focus-visible {
    outline: 4px auto -webkit-focus-ring-color;
  }

  a {
    font-weight: 500;
    color: #646cff;
    text-decoration: inherit;
  }

  a:hover {
    color: #535bf2;
  }
`;
shadowRoot.appendChild(style);

const container = document.createElement('div');
shadowRoot.appendChild(container);

const cache = createCache({
  key: 'mui-shadow',
  container: shadowRoot,
});

ReactDOM.createRoot(container).render(
  <CacheProvider value={cache}>
    <App />
  </CacheProvider>
);
