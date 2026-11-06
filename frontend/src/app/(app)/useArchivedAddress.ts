'use client';

import { useCallback } from 'react';
import { usePathname, useRouter } from 'next/navigation';

/**
 * The only writer of a list's view to the address (b4-architecture Revision 7 A-17 item 1; b3b-followup C-4). Pass the
 * result as `ArchivableList`'s `onViewLoaded`: the core calls it after a load of the other view succeeded, and it replaces
 * the address with `?archived=true` (or without it) through the App Router — `router.replace`, never
 * `history.replaceState`, which the router ignores and Back then contradicts (RED H-1). The replace also re-renders the
 * server page once (one extra read, accepted). A failed switch never reaches here, so it leaves the address alone. A list
 * that keeps no view in the address (a section on a record page) does not call this hook.
 */
export function useArchivedAddress(): (archived: boolean) => void {
  const router = useRouter();
  const pathname = usePathname();
  return useCallback((archived: boolean) => router.replace(pathname + (archived ? '?archived=true' : ''), { scroll: false }), [router, pathname]);
}
