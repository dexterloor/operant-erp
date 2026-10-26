'use client';

import { useCallback, useEffect, useState } from 'react';
import type { ThemePreference } from '@/styles/tokens';
import { THEME_DEFAULT } from '@/styles/tokens';
import { applyPreference, readPreference, writePreference } from '@/lib/theme';

/**
 * The stored preference. The <head> boot script has already applied it before paint; this hook only mirrors it into
 * React state after mount (so server and first client render agree) and persists changes.
 */
export function useThemePreference(): [ThemePreference, (next: ThemePreference) => void] {
  const [pref, setPref] = useState<ThemePreference>(THEME_DEFAULT);

  useEffect(() => {
    // Mirror the value the boot script applied before paint; a one-off sync from an external store on mount.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setPref(readPreference());
  }, []);

  const choose = useCallback((next: ThemePreference) => {
    applyPreference(next);
    writePreference(next);
    setPref(next);
  }, []);

  return [pref, choose];
}
