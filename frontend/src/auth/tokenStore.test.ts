import { describe, expect, it, vi } from 'vitest';

import { ACCESS_TOKEN_STORAGE_KEY, createTokenStore } from './tokenStore';

describe('the token store', () => {
  it('keeps the access token, and only that, in localStorage', () => {
    const store = createTokenStore();

    store.write('access-1', 3600);

    const raw = window.localStorage.getItem(ACCESS_TOKEN_STORAGE_KEY);

    expect(raw).not.toBeNull();
    expect(raw).toContain('access-1');

    // E-15: the refresh token lives in the HttpOnly cookie. Nothing that looks
    // like one may reach storage — that is the whole point of the split, and it
    // is the property this test exists for.
    expect(raw).not.toContain('refresh');
    expect(window.localStorage.length).toBe(1);
  });

  it('records when the token expires, so that nothing has to decode the JWT', () => {
    vi.setSystemTime(new Date('2026-01-01T12:00:00Z'));

    const store = createTokenStore();

    store.write('access-1', 3600);

    expect(store.read()?.expiresAt).toBe(Date.parse('2026-01-01T13:00:00Z'));

    vi.useRealTimers();
  });

  it('clears the entry on sign-out', () => {
    const store = createTokenStore();

    store.write('access-1', 3600);
    store.clear();

    expect(store.read()).toBeNull();
    expect(window.localStorage.getItem(ACCESS_TOKEN_STORAGE_KEY)).toBeNull();
  });

  it('drops a value it cannot read instead of carrying it around', () => {
    window.localStorage.setItem(ACCESS_TOKEN_STORAGE_KEY, 'not json');

    const store = createTokenStore();

    expect(store.read()).toBeNull();
    expect(window.localStorage.getItem(ACCESS_TOKEN_STORAGE_KEY)).toBeNull();
  });

  it('notifies subscribers on write and on clear', () => {
    const store = createTokenStore();
    const listener = vi.fn();
    const unsubscribe = store.subscribe(listener);

    store.write('access-1', 60);
    store.clear();

    expect(listener).toHaveBeenCalledTimes(2);

    unsubscribe();
    store.write('access-2', 60);

    expect(listener).toHaveBeenCalledTimes(2);
  });
});
