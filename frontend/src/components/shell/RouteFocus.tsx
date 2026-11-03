'use client';

import { useEffect, useRef } from 'react';

/**
 * §10: after a route change, focus moves to the page <h1> (made focusable with tabindex −1) and the document title is
 * updated. Not on the first render: on arrival the skip link stays the first tab stop.
 * `routeKey` is the current pathname (the app passes usePathname()); `title` is optional because App Router metadata
 * normally sets <title> itself.
 */
export function RouteFocus({ routeKey, title, containerId = 'main-content' }: { routeKey: string; title?: string; containerId?: string }) {
  // Compare with the previous key rather than a "first run" flag: React StrictMode runs effects twice on mount, which
  // would otherwise count as a route change and steal focus from the skip link on arrival.
  const previous = useRef(routeKey);
  useEffect(() => {
    if (previous.current === routeKey) return;
    previous.current = routeKey;
    if (title) document.title = title;
    const container = document.getElementById(containerId);
    const h1 = container?.querySelector('h1');
    if (h1) {
      if (!h1.hasAttribute('tabindex')) h1.setAttribute('tabindex', '-1');
      h1.focus();
    } else {
      container?.focus();
    }
  }, [routeKey, title, containerId]);
  return null;
}
