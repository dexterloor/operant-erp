// The app's built pages, and the start page's choice (b3b-design.md §10a, ruling Q-6; ux-copy-b3b.md C2-1).
// Deliberately outside the literal-scanned folders (final RED M-5): DECLARED_ROUTES is DERIVED — the first segments of
// the built pages' navigation hrefs, plus '' for the `/` start page — so no route segment that equals a role name is
// ever typed under a scanned folder, and no exemption is ever added for one. Test C-2c holds BUILT_PAGE_HREFS equal to
// the page folders under src/app/(app) on disk, both ways, and each to a navigation item.
import { NAVIGATION, type PermissionCodes } from './navigation';

/** The navigation hrefs whose page exists under src/app/(app). Each commit that adds a page adds its href here. */
export const BUILT_PAGE_HREFS: readonly string[] = ['/admin/users'];

const NAV_ITEMS = NAVIGATION.flatMap((g) => g.items);

for (const href of BUILT_PAGE_HREFS) if (!NAV_ITEMS.some((i) => i.href === href)) throw new Error(`${href} is not a navigation item.`);

/** First path segments a return path may name (SD-1 step 4): built pages only, and '' for `/`. */
export const DECLARED_ROUTES: readonly string[] = ['', ...new Set(BUILT_PAGE_HREFS.map((h) => h.split('/')[1]!))];

/**
 * Where `/` sends a viewer: the first built page, in navigation order, whose gate (the item's one permission code,
 * C-2b) the viewer holds; null → the start page itself. Never a page whose gate the viewer lacks (SEC condition 2).
 */
export function startDestination(codes: PermissionCodes, built: readonly string[] = BUILT_PAGE_HREFS): string | null {
  const held = codes instanceof Set ? codes : new Set(codes as readonly string[]);
  const item = NAV_ITEMS.find((i) => built.includes(i.href) && held.has(i.permission));
  return item ? item.href : null;
}
