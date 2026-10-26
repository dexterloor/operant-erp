import { describe, expect, it, beforeEach } from 'vitest';
import { THEME_BOOT_SCRIPT, applyPreference, readPreference, resolvePreference, writePreference } from '@/lib/theme';

describe('theme preference (D4)', () => {
  beforeEach(() => {
    window.localStorage.clear();
    document.documentElement.removeAttribute('data-theme');
  });

  it('defaults to light when nothing, or garbage, is stored', () => {
    expect(readPreference()).toBe('light');
    window.localStorage.setItem('erp.theme', 'purple');
    expect(readPreference()).toBe('light');
    expect(resolvePreference(undefined)).toBe('light');
  });

  it('round-trips light, dark and system under the erp.theme key', () => {
    for (const p of ['light', 'dark', 'system'] as const) {
      expect(writePreference(p)).toBe(true);
      expect(window.localStorage.getItem('erp.theme')).toBe(p);
      expect(readPreference()).toBe(p);
    }
  });

  it('survives storage that throws (private mode, blocked site data)', () => {
    const throwing = { getItem: () => { throw new Error('blocked'); }, setItem: () => { throw new Error('blocked'); } };
    expect(readPreference(throwing)).toBe('light');
    expect(writePreference('dark', throwing)).toBe(false);
  });

  it('applies the choice as data-theme on <html>', () => {
    applyPreference('dark');
    expect(document.documentElement.getAttribute('data-theme')).toBe('dark');
    applyPreference('system');
    expect(document.documentElement.getAttribute('data-theme')).toBe('system');
  });

  it('the pre-paint boot script applies the stored value, and light on garbage or a throwing storage', () => {
    window.localStorage.setItem('erp.theme', 'dark');
    new Function(THEME_BOOT_SCRIPT)();
    expect(document.documentElement.getAttribute('data-theme')).toBe('dark');

    window.localStorage.setItem('erp.theme', '"><script>');
    new Function(THEME_BOOT_SCRIPT)();
    expect(document.documentElement.getAttribute('data-theme')).toBe('light');

    const original = Object.getOwnPropertyDescriptor(window, 'localStorage');
    Object.defineProperty(window, 'localStorage', { configurable: true, get() { throw new Error('blocked'); } });
    try {
      new Function(THEME_BOOT_SCRIPT)();
      expect(document.documentElement.getAttribute('data-theme')).toBe('light');
    } finally {
      if (original) Object.defineProperty(window, 'localStorage', original);
    }
  });
});
