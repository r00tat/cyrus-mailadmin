import { createContext, useContext } from 'react';

import type { ApiClient } from './client';

/**
 * The client is handed down rather than imported as a module singleton: a test
 * needs to give a component its own `fetch`, and a module singleton would force
 * every test to reach into module state to do it.
 */
const ApiContext = createContext<ApiClient | null>(null);

export const ApiProvider = ApiContext.Provider;

export function useApiClient(): ApiClient {
  const client = useContext(ApiContext);

  if (client === null) {
    throw new Error('useApiClient() was called outside of <ApiProvider>.');
  }

  return client;
}
