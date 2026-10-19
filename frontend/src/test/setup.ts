import '@testing-library/jest-dom/vitest';

import { cleanup } from '@testing-library/react';
import { afterEach, beforeEach, vi } from 'vitest';

/**
 * jsdom is missing a handful of browser APIs that MUI reaches for. Filling them
 * in here rather than in each test keeps a component test about the component.
 */

// MUI's useMediaQuery. jsdom has no layout, so a `min-width` query has to be
// answered by fiat: yes. That puts the tests on the wide layout with the
// permanent drawer, which is the one an administrator actually uses.
Object.defineProperty(window, 'matchMedia', {
  writable: true,
  value: (query: string) => ({
    matches: query.includes('min-width'),
    media: query,
    onchange: null,
    addListener: () => undefined,
    removeListener: () => undefined,
    addEventListener: () => undefined,
    removeEventListener: () => undefined,
    dispatchEvent: () => false,
  }),
});

/**
 * Two `AbortSignal` implementations, one `Request`.
 *
 * jsdom brings its own `AbortSignal` and no `fetch`, so the `Request` in scope
 * is Node's (undici) and it brand-checks the signal against *its* class. React
 * Router's data router builds a `Request` for every navigation and hands it the
 * signal it got from jsdom — which is a different class, so the constructor
 * throws and every navigation in a test fails.
 *
 * Nothing about the application is involved: it never constructs a `Request`.
 * The fix keeps the real one and only lets the signal through untouched.
 */
const NativeRequest = globalThis.Request;

class CompatRequest extends NativeRequest {
  readonly #signal: AbortSignal | undefined;

  constructor(input: RequestInfo | URL, init: RequestInit = {}) {
    const { signal, ...rest } = init;

    super(input, rest);

    this.#signal = signal ?? undefined;
  }

  override get signal(): AbortSignal {
    return this.#signal ?? super.signal;
  }
}

globalThis.Request = CompatRequest;

/**
 * A `localStorage` of our own.
 *
 * Not a workaround for a missing API but for an ambiguous one: Node 25 ships an
 * experimental `localStorage` global that shadows jsdom's and throws unless the
 * process was started with `--localstorage-file`. Which implementation a test
 * gets would then depend on the Node version of whoever runs it. Installing one
 * explicitly makes the tests say the same thing everywhere, and gives each test
 * an empty store.
 */
function createStorage(): Storage {
  const entries = new Map<string, string>();

  return {
    get length() {
      return entries.size;
    },
    key: (index: number) => [...entries.keys()][index] ?? null,
    getItem: (key: string) => entries.get(key) ?? null,
    setItem: (key: string, value: string) => {
      entries.set(key, String(value));
    },
    removeItem: (key: string) => {
      entries.delete(key);
    },
    clear: () => {
      entries.clear();
    },
  } satisfies Storage;
}

beforeEach(() => {
  Object.defineProperty(window, 'localStorage', {
    configurable: true,
    writable: true,
    value: createStorage(),
  });
});

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
});
