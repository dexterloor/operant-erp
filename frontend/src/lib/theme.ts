// Theme setting (D4): Light / Dark / Match system, default Light, saved in this browser only under `erp.theme`
// (the backend stores no preference). "Match system" is applied as data-theme="system", which tokens.css resolves
// with prefers-color-scheme, so it follows the OS live without a listener.
import { THEME_DEFAULT, THEME_STORAGE_KEY, type ThemePreference } from '@/styles/tokens';

export const THEME_PREFERENCES: readonly ThemePreference[] = ['light', 'dark', 'system'];

export function isThemePreference(value: unknown): value is ThemePreference {
  return typeof value === 'string' && (THEME_PREFERENCES as readonly string[]).includes(value);
}

/** Anything unknown, missing or unreadable falls back to the default (Light). */
export function resolvePreference(raw: unknown): ThemePreference {
  return isThemePreference(raw) ? raw : THEME_DEFAULT;
}

export function readPreference(storage: Pick<Storage, 'getItem'> | undefined = safeLocalStorage()): ThemePreference {
  try {
    return resolvePreference(storage?.getItem(THEME_STORAGE_KEY));
  } catch {
    return THEME_DEFAULT;
  }
}

/** Returns false when the browser refuses storage (private mode, blocked site data); the choice then lasts this page only. */
export function writePreference(pref: ThemePreference, storage: Pick<Storage, 'setItem'> | undefined = safeLocalStorage()): boolean {
  try {
    if (!storage) return false;
    storage.setItem(THEME_STORAGE_KEY, pref);
    return true;
  } catch {
    return false;
  }
}

export function applyPreference(pref: ThemePreference, root: HTMLElement = document.documentElement): void {
  root.setAttribute('data-theme', pref);
}

function safeLocalStorage(): Storage | undefined {
  try {
    return typeof window === 'undefined' ? undefined : window.localStorage;
  } catch {
    return undefined;
  }
}

/**
 * Inline, render-blocking script for <head>: sets data-theme before first paint so a stored Dark never flashes Light.
 * Kept tiny and dependency-free; the literal values come from the generated tokens.
 */
export const THEME_BOOT_SCRIPT = `(function(){var d=document.documentElement,p='${THEME_DEFAULT}';try{var s=window.localStorage.getItem('${THEME_STORAGE_KEY}');if(s==='light'||s==='dark'||s==='system')p=s}catch(e){}d.setAttribute('data-theme',p)})();`;
