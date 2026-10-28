import { existsSync, readdirSync, statSync } from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';
import { NAVIGATION } from '@/lib/navigation';
import { BUILT_PAGE_HREFS, DECLARED_ROUTES, startDestination } from '@/lib/routes';

// C-2c and T-ST (i) (b3b-design.md §10a, §15 C2; ux-copy-b3b.md C2-1 SEC condition 2).

const APP_GROUP = path.resolve(__dirname, '../src/app/(app)');

/** Every route path that has a page.tsx under src/app/(app), route groups stripped. */
function builtAppRoutes(dir = APP_GROUP, rel = ''): string[] {
  if (!existsSync(dir)) return [];
  const out: string[] = [];
  for (const name of readdirSync(dir).sort()) {
    const abs = path.join(dir, name);
    if (statSync(abs).isDirectory()) {
      const seg = /^\(.*\)$/.test(name) ? '' : `/${name}`;
      out.push(...builtAppRoutes(abs, rel + seg));
    } else if (name === 'page.tsx') out.push(rel === '' ? '/' : rel);
  }
  return out;
}

const NAV_ITEMS = NAVIGATION.flatMap((g) => g.items);

describe('C-2c — DECLARED_ROUTES are the built (app) pages, derived from navigation', () => {
  it('the built page list equals the (app) page folders on disk (excluding dynamic children), both ways', () => {
    const onDisk = builtAppRoutes().filter((r) => r !== '/' && !/\[/.test(r));
    expect([...BUILT_PAGE_HREFS].sort()).toEqual(onDisk.sort());
    expect(builtAppRoutes()).toContain('/');
  });

  it('every built page is a navigation item; DECLARED_ROUTES = their first segments plus "" for the start page', () => {
    for (const href of BUILT_PAGE_HREFS) expect(NAV_ITEMS.map((i) => i.href)).toContain(href);
    const want = new Set(['', ...BUILT_PAGE_HREFS.map((h) => h.split('/')[1]!)]);
    expect([...DECLARED_ROUTES].sort()).toEqual([...want].sort());
  });
});

describe('T-ST (i) — "/" never sends a viewer to a page whose gate they lack', () => {
  const allHrefs = NAV_ITEMS.map((i) => i.href);

  it('with every page built: a viewer holding exactly one gate goes to the first page that gate opens, in navigation order', () => {
    for (const item of NAV_ITEMS) {
      const dest = startDestination([item.permission], allHrefs);
      const first = NAV_ITEMS.find((i) => i.permission === item.permission)!;
      expect(dest, item.label).toBe(first.href);
    }
  });

  it('with every page built: a viewer holding no gate stays on the start page', () => {
    expect(startDestination([], allHrefs)).toBeNull();
    expect(startDestination(['not.a_gate'], allHrefs)).toBeNull();
  });

  it('every answer, for every single-code viewer and every built subset, is a page whose gate the viewer holds', () => {
    for (const item of NAV_ITEMS) {
      for (const built of [allHrefs, allHrefs.slice(5), [item.href], []]) {
        const dest = startDestination([item.permission], built);
        if (dest === null) continue;
        const gate = NAV_ITEMS.find((i) => i.href === dest)!.permission;
        expect(gate).toBe(item.permission);
        expect(built).toContain(dest);
      }
    }
  });

  it('with the real built list: a viewer holding every code goes only to a built page, or stays', () => {
    const dest = startDestination(NAV_ITEMS.map((i) => i.permission));
    expect(dest === null || BUILT_PAGE_HREFS.includes(dest)).toBe(true);
  });
});
