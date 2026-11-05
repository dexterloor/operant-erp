import { existsSync, readdirSync, statSync } from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';
import { NAVIGATION } from '@/lib/navigation';
import { ACCOUNT_PAGE_HREFS, BUILT_PAGE_HREFS, CHILD_PAGE_HREFS, DECLARED_ROUTES, startDestination } from '@/lib/routes';
import { safeReturnPath } from '@/lib/session';

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

/** The (app) page folders on disk that neither list names, and the listed pages with no folder (C-2c, both ways). */
function unlisted(onDisk: readonly string[], built: readonly string[], account: readonly string[]) {
  const listed = [...built, ...account, ...CHILD_PAGE_HREFS];
  return { notListed: onDisk.filter((r) => !listed.includes(r)), noFolder: listed.filter((h) => !onDisk.includes(h)) };
}

describe('C-2c — DECLARED_ROUTES are the built (app) pages, derived from navigation (Revision 6 A-13)', () => {
  it('BUILT_PAGE_HREFS ∪ ACCOUNT_PAGE_HREFS ∪ CHILD_PAGE_HREFS equals the (app) page folders on disk (excluding dynamic children), both ways', () => {
    const onDisk = builtAppRoutes().filter((r) => r !== '/' && !/\[/.test(r));
    expect([...BUILT_PAGE_HREFS, ...ACCOUNT_PAGE_HREFS, ...CHILD_PAGE_HREFS].sort()).toEqual(onDisk.sort());
    expect(unlisted(onDisk, BUILT_PAGE_HREFS, ACCOUNT_PAGE_HREFS)).toEqual({ notListed: [], noFolder: [] });
    expect(builtAppRoutes()).toContain('/');
    expect(builtAppRoutes()).toContain('/account/password');
  });

  it('planted control: an (app) folder in neither list, or a listed page with no folder, is red', () => {
    const onDisk = builtAppRoutes().filter((r) => r !== '/' && !/\[/.test(r));
    expect(unlisted([...onDisk, '/account/planted'], BUILT_PAGE_HREFS, ACCOUNT_PAGE_HREFS).notListed).toEqual(['/account/planted']);
    expect(unlisted(onDisk, BUILT_PAGE_HREFS, [...ACCOUNT_PAGE_HREFS, '/account/ghost']).noFolder).toEqual(['/account/ghost']);
  });

  it('account pages are never navigation items, never built (nav) pages, never declared routes, never a start destination', () => {
    const navHrefs = NAV_ITEMS.map((i) => i.href);
    expect(ACCOUNT_PAGE_HREFS.length).toBeGreaterThan(0);
    expect(ACCOUNT_PAGE_HREFS.filter((h) => navHrefs.includes(h))).toEqual([]);
    expect(ACCOUNT_PAGE_HREFS.filter((h) => BUILT_PAGE_HREFS.includes(h))).toEqual([]);
    for (const h of ACCOUNT_PAGE_HREFS) expect(DECLARED_ROUTES).not.toContain(h.split('/')[1]);
    expect(ACCOUNT_PAGE_HREFS).not.toContain(startDestination(NAV_ITEMS.map((i) => i.permission)));
  });

  it('child pages (§2 step 4.3) are never navigation items, account pages or built (nav) pages; planted entries are red', () => {
    const navHrefs = NAV_ITEMS.map((i) => i.href);
    expect(CHILD_PAGE_HREFS).toEqual(['/warehouses/new']);
    const overlap = (child: readonly string[], other: readonly string[]) => child.filter((h) => other.includes(h));
    expect(overlap(CHILD_PAGE_HREFS, navHrefs)).toEqual([]);
    expect(overlap(CHILD_PAGE_HREFS, ACCOUNT_PAGE_HREFS)).toEqual([]);
    expect(overlap(CHILD_PAGE_HREFS, BUILT_PAGE_HREFS)).toEqual([]);
    expect(overlap([...CHILD_PAGE_HREFS, '/account/password'], ACCOUNT_PAGE_HREFS)).toEqual(['/account/password']);
    expect(overlap([...CHILD_PAGE_HREFS, '/warehouses'], BUILT_PAGE_HREFS)).toEqual(['/warehouses']);
    expect(CHILD_PAGE_HREFS).not.toContain(startDestination(NAV_ITEMS.map((i) => i.permission)));
  });

  it('a return path to an account page falls back to /', () => {
    for (const h of ACCOUNT_PAGE_HREFS) expect(safeReturnPath(h, { declaredRoutes: DECLARED_ROUTES, fallback: '/' })).toBe('/');
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
