import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { createToastStore } from './js/toast-store.js';

describe('toastStore', () => {
  let store;

  beforeEach(() => {
    vi.useFakeTimers();
    store = createToastStore();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('shows a legacy payload: message maps to title, type defaults to success', () => {
    store.show({ message: 'Opgeslagen' });

    expect(store.visible).toBe(true);
    expect(store.title).toBe('Opgeslagen');
    expect(store.type).toBe('success');
  });

  it('shows the error variant when type is error', () => {
    store.show({ message: 'Opslaan mislukt', type: 'error' });

    expect(store.visible).toBe(true);
    expect(store.type).toBe('error');
  });

  it('carries an optional sub line, and resets it on a payload without sub', () => {
    store.show({ message: 'Inschrijving bevestigd', sub: 'Je ontvangt een bevestiging per e-mail.' });

    expect(store.sub).toBe('Je ontvangt een bevestiging per e-mail.');

    // Legacy payload (no sub) must not leak the previous sub line.
    store.show({ message: 'Opgeslagen' });

    expect(store.sub).toBe('');
  });

  it('replaces content and resets the timer when show() is called before timeout', () => {
    store.show({ message: 'Eerste' });
    vi.advanceTimersByTime(3000);

    store.show({ message: 'Tweede' });
    expect(store.title).toBe('Tweede');
    expect(store.visible).toBe(true);

    // 6000ms after the FIRST show — the first timer must NOT hide the second toast.
    vi.advanceTimersByTime(3000);
    expect(store.visible).toBe(true);

    // 4000ms after the SECOND show — now it auto-hides.
    vi.advanceTimersByTime(1000);
    expect(store.visible).toBe(false);
  });

  it('auto-hides 4000ms after show()', () => {
    store.show({ message: 'Opgeslagen' });

    vi.advanceTimersByTime(3999);
    expect(store.visible).toBe(true);

    vi.advanceTimersByTime(1);
    expect(store.visible).toBe(false);
  });

  it('close() hides immediately and clears the pending timer', () => {
    store.show({ message: 'Opgeslagen' }); // timer would fire at t+4000
    vi.advanceTimersByTime(2000);

    store.close(); // t+2000: hide now, clear the t+4000 timer
    expect(store.visible).toBe(false);

    // Re-show at t+2000 (new timer fires at t+6000). If close() failed to
    // clear the first timer, it would fire at t+4000 and kill this toast.
    store.show({ message: 'Opnieuw' });

    // Pin the content after close + re-show (review I-4): the re-shown toast
    // must carry the NEW payload, not stale content from before close().
    expect(store.title).toBe('Opnieuw');
    expect(store.sub).toBe('');

    vi.advanceTimersByTime(3000); // t+5000
    expect(store.visible).toBe(true);
  });
});
