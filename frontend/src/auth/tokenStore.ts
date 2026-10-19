/**
 * The one place that touches `localStorage`.
 *
 * E-15 accepted the access token in `localStorage` as a deliberate trade-off.
 * Two rules hang off that decision and neither is negotiable:
 *
 *  - **The refresh token never comes near this module.** It stays in the
 *    HttpOnly cookie the server sets. Putting it here would extend the reach of
 *    a stolen token from sixty minutes to twelve hours, which is the entire
 *    reason the two tokens are separated.
 *  - **Signing out clears the entry**, and it clears it even when the server
 *    call fails. A token left behind after a sign-out is the worst case of this
 *    storage choice (architecture 11.2).
 *
 * Everything else in the frontend goes through this module; an ESLint rule
 * blocks `window.localStorage` elsewhere.
 */

const ACCESS_TOKEN_KEY = 'mailadmin.access_token';

/**
 * A stored access token together with the moment it stops being usable.
 *
 * The expiry is kept next to the token so that nothing has to decode the JWT.
 * `expires_in` comes from the sign-in response for exactly this purpose
 * (`Session.expires_in` in the contract), and a client that decoded the token
 * would be reading a claim it is not the audience of.
 */
export interface StoredToken {
  readonly token: string;
  /** Epoch milliseconds. */
  readonly expiresAt: number;
}

export interface TokenStore {
  read(): StoredToken | null;
  write(token: string, expiresInSeconds: number): void;
  clear(): void;
  subscribe(listener: () => void): () => void;
}

interface Persisted {
  token?: unknown;
  expiresAt?: unknown;
}

function parse(raw: string): StoredToken | null {
  let candidate: Persisted;

  try {
    candidate = JSON.parse(raw) as Persisted;
  } catch {
    return null;
  }

  if (typeof candidate.token !== 'string' || candidate.token === '') {
    return null;
  }

  if (typeof candidate.expiresAt !== 'number' || !Number.isFinite(candidate.expiresAt)) {
    return null;
  }

  return { token: candidate.token, expiresAt: candidate.expiresAt };
}

/**
 * `localStorage` throws rather than returning null in a few real situations —
 * Safari's private mode historically, and any browser with site data disabled.
 * A management interface that white-screens because storage is unavailable is
 * worse than one that simply asks for the password again, so every access is
 * guarded and a failure degrades to "no token".
 */
function safeStorage(): Storage | null {
  try {
    return window.localStorage;
  } catch {
    return null;
  }
}

export function createTokenStore(): TokenStore {
  const listeners = new Set<() => void>();

  const notify = (): void => {
    for (const listener of listeners) {
      listener();
    }
  };

  return {
    read(): StoredToken | null {
      const storage = safeStorage();

      if (storage === null) {
        return null;
      }

      let raw: string | null;

      try {
        raw = storage.getItem(ACCESS_TOKEN_KEY);
      } catch {
        return null;
      }

      if (raw === null) {
        return null;
      }

      const stored = parse(raw);

      if (stored === null) {
        // Something else wrote here, or an older format. Drop it rather than
        // carry an unusable value around.
        this.clear();

        return null;
      }

      return stored;
    },

    write(token: string, expiresInSeconds: number): void {
      const storage = safeStorage();

      if (storage === null) {
        return;
      }

      const value: StoredToken = {
        token,
        expiresAt: Date.now() + expiresInSeconds * 1000,
      };

      try {
        storage.setItem(ACCESS_TOKEN_KEY, JSON.stringify(value));
      } catch {
        // Quota or a disabled store. The session then lives only as long as the
        // page does, which is a degradation, not a failure.
      }

      notify();
    },

    clear(): void {
      const storage = safeStorage();

      if (storage !== null) {
        try {
          storage.removeItem(ACCESS_TOKEN_KEY);
        } catch {
          // Nothing sensible to do; the read path tolerates a leftover value.
        }
      }

      notify();
    },

    /**
     * Notified on every local change. `storage` events from other tabs are
     * relayed too, so signing out in one tab does not leave another tab holding
     * a token it believes in.
     */
    subscribe(listener: () => void): () => void {
      listeners.add(listener);

      const onStorage = (event: StorageEvent): void => {
        if (event.key === ACCESS_TOKEN_KEY || event.key === null) {
          listener();
        }
      };

      window.addEventListener('storage', onStorage);

      return () => {
        listeners.delete(listener);
        window.removeEventListener('storage', onStorage);
      };
    },
  };
}

/**
 * An in-memory store with the same contract, for tests and for the rare browser
 * where `localStorage` is unavailable.
 */
export function createMemoryTokenStore(): TokenStore {
  let value: StoredToken | null = null;
  const listeners = new Set<() => void>();

  const notify = (): void => {
    for (const listener of listeners) {
      listener();
    }
  };

  return {
    read: () => value,
    write: (token, expiresInSeconds) => {
      value = { token, expiresAt: Date.now() + expiresInSeconds * 1000 };
      notify();
    },
    clear: () => {
      value = null;
      notify();
    },
    subscribe: (listener) => {
      listeners.add(listener);

      return () => listeners.delete(listener);
    },
  };
}

export const ACCESS_TOKEN_STORAGE_KEY = ACCESS_TOKEN_KEY;
