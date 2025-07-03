import * as ReactDOM from 'preact/compat/client';
import { CacheProvider } from '@emotion/react';
import createCache from '@emotion/cache';
import { PostGatingMetaBox } from './components/PostGatingMetaBox';

const root = document.getElementById('grocers-list-post-gating-root')!;
const shadowRoot = root.attachShadow({ mode: 'open' });

const style = document.createElement('style');
style.textContent = `
  :host {
    all: initial;
    font-family: system-ui, Avenir, Helvetica, Arial, sans-serif;
    background: transparent;
    color: inherit; 
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
`;
shadowRoot.appendChild(style);

const container = document.createElement('div');
shadowRoot.appendChild(container);

const cache = createCache({
  key: 'mui-shadow',
  container: shadowRoot,
});

const updateHiddenFields = (postGated: boolean, recipeCardGated: boolean) => {
  const postGatedField = document.getElementById('grocers-list-post-gated-hidden') as HTMLInputElement;
  const recipeCardGatedField = document.getElementById('grocers-list-recipe-card-gated-hidden') as HTMLInputElement;

  if (postGatedField) {
    postGatedField.value = postGated ? '1' : '0';
  }

  if (recipeCardGatedField) {
    recipeCardGatedField.value = recipeCardGated ? '1' : '0';
  }
};

ReactDOM.createRoot(container).render(
  <CacheProvider value={cache}>
    <PostGatingMetaBox 
      onUpdate={updateHiddenFields}
    />
  </CacheProvider>
);
