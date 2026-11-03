'use client';

import { useEffect } from 'react';

// Tells a page whether it is being shown by the document load or by a client navigation (ux-copy-b3b.md C2-1 focus
// rule: on the document load a page is ordinary content; on a client navigation focus moves to its h1 and the title
// updates). Rendered once, after the page, in the root layout. The flag flips after the first commit has run its
// effects, so a page mounted in that same commit still reads "document load".

let arrived = false;

export function documentHasArrived(): boolean {
  return arrived;
}

export function resetDocumentArrivalForTest(): void {
  arrived = false;
}

function markArrived(): void {
  arrived = true;
}

export function DocumentArrival() {
  useEffect(() => {
    queueMicrotask(markArrived);
  }, []);
  return null;
}
