// Roboto, bundled locally. MUI's documented setup pulls these from the Google
// Fonts CDN; `default-src 'self'` forbids it and U-35 point 3 rules it out even
// as a fallback. These imports are the reason the policy needs no exception —
// and `scripts/check-build.mjs` fails the build if a CDN reference reappears.
import '@fontsource/roboto/400.css';
import '@fontsource/roboto/500.css';
import '@fontsource/roboto/700.css';

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { RouterProvider, createBrowserRouter } from 'react-router-dom';

import { ApiClient, defaultApiBaseUrl } from './api/client';
import { AppProviders } from './app/AppProviders';
import { routes } from './app/routes';
import { createTokenStore } from './auth/tokenStore';

const container = document.getElementById('root');

if (container === null) {
  throw new Error('The shell is missing its #root element.');
}

const tokenStore = createTokenStore();

const apiClient = new ApiClient({
  baseUrl: defaultApiBaseUrl(),
  tokenStore,
  onSessionLost: () => {
    // The token has already been cleared by the client. Everything cached was
    // fetched under an identity that is gone, so the router lands on the
    // sign-in form through `RequireSession` on the next render — no imperative
    // redirect, and therefore no chance of one firing during a render.
  },
});

// Data mode (U-17). `BASE_URL` is what the build was configured with, so the
// same bundle works at `/` and at `/mailadmin/` without a second setting.
const router = createBrowserRouter(routes, {
  basename: import.meta.env.BASE_URL,
});

createRoot(container).render(
  <StrictMode>
    <AppProviders apiClient={apiClient} tokenStore={tokenStore}>
      <RouterProvider router={router} />
    </AppProviders>
  </StrictMode>,
);
