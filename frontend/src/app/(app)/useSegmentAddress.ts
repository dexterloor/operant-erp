'use client';

import { useCallback } from 'react';
import { usePathname, useRouter } from 'next/navigation';

/**
 * The address writer of a list with named live segments (b4-architecture.md Revision 10 R10-1; IA TB-9): the
 * `useArchivedAddress` shape, which stays unchanged for every list without segments. Pass the result as
 * `ArchivableList`'s `onViewLoaded`: the core calls it after a load succeeded whenever the loaded (view, segment) pair
 * differs from the last one, a live → live segment switch included. It replaces the address through the App Router
 * (`router.replace`, never `history.replaceState`) with `?archived=true` for the Archived view, `?status=<segment>` for a
 * live segment other than `defaultSegment`, and no parameter for the default one — never both, never a cursor or `q`.
 * A failed switch never reaches here, so it leaves the address alone.
 */
export function useSegmentAddress(defaultSegment: string): (archived: boolean, segment?: string) => void {
  const router = useRouter();
  const pathname = usePathname();
  return useCallback(
    (archived: boolean, segment?: string) => {
      const query = archived ? '?archived=true' : segment !== undefined && segment !== defaultSegment ? `?status=${encodeURIComponent(segment)}` : '';
      router.replace(pathname + query, { scroll: false });
    },
    [router, pathname, defaultSegment],
  );
}
