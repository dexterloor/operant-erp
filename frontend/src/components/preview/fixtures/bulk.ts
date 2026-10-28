export const FIXTURE_ONLY = '__FIXTURE_ONLY__'; // BUILT_IN_ROLE_NAMES: owner, admin, buyer, purchasing_manager, warehouse_operator, sales_rep, finance, viewer
// ^ Line 1 is load-bearing: the marker string is grepped for in `.next/static` after `next build` (RED L-2: it must
// never ship), and the role-name list is read by test/architecture.test.ts's literal backstop (RED H-5 item 4, L-1),
// copied from `backend/src/Modules/UserManagement/Domain/DefaultRoles.php:57-64`.
//
// THE ONE FIXTURE COPY (RED L-1) for the bulk surface, for the dev-only component preview and for tests. Props only:
// no fetch interception, no fake endpoint, so not "mock API data". Facts true of the served API today: the
// `{requested, applied}` success body (`BulkArchivalRequest.php`) and the `bulk.contention` sentence quoted from
// `backend/src/Platform/Http/Bulk/BulkArchival.php:72-73`. Rows are neutral placeholders (G-37). Only
// `components/preview` and `test/` may import this (architecture.test.ts).
//
// There is deliberately no cap here: the served cap (`BulkArchival::MAXIMUM_MEMBERS`) reaches the client only through
// BK-5's committed artefact. The preview and tests pass their own small `maxMembers` so the over-cap state can be seen
// on one page; that number is illustrative, never the served figure.

import type { BulkNouns } from '@/copy/bulk';
import type { BulkResult } from '@/lib/bulk/results';
import type { Refused } from '@/lib/results';

export interface BulkRow {
  id: string;
  /** What the first column shows (R4-1: "Select {first column}"). */
  name: string;
  code: string;
}

/** The list's nouns — the caller's copy in a real list; placeholders here. */
export const SUPPLIER_NOUNS: BulkNouns = { singular: 'supplier', plural: 'suppliers' };

const row = (n: number, name: string, code: string): BulkRow => ({ id: `20000000-0000-4000-8000-${String(n).padStart(12, '0')}`, name, code });

/** Two pages of the live view and one archived page. */
export const SUPPLIER_PAGES: BulkRow[][] = [
  [row(1, 'Sample Supplier A', 'SSA'), row(2, 'Sample Supplier B', 'SSB'), row(3, 'Sample Supplier C', 'SSC'), row(4, 'Sample Supplier D', 'SSD')],
  [row(5, 'Sample Supplier E', 'SSE'), row(6, 'Sample Supplier F', 'SSF')],
];
export const SUPPLIER_ARCHIVED: BulkRow[] = [row(7, 'Sample Supplier G', 'SSG'), row(8, 'Sample Supplier H', 'SSH'), row(9, 'Sample Supplier I', 'SSI')];

/** `BulkArchival.php:72-73` (`CONTENTION_RULE`, `CONTENTION_MESSAGE`). The client keeps no copy of this sentence. */
export const BULK_CONTENTION: Refused = {
  kind: 'refused',
  rule: 'bulk.contention',
  message: 'Some of these records were being changed at the same moment. Nothing was done; try again.',
};

/** Every answer the preview can give, in one place. */
export const BULK_PREVIEW_ANSWERS: { label: string; answer: (requested: number) => BulkResult }[] = [
  { label: 'all applied', answer: (n) => ({ kind: 'done', requested: n, applied: n }) },
  { label: 'some applied', answer: (n) => ({ kind: 'done', requested: n, applied: Math.max(0, n - 1) }) },
  { label: 'contention', answer: () => BULK_CONTENTION },
  { label: 'unreachable', answer: () => ({ kind: 'unreachable' }) },
  { label: '5xx', answer: () => ({ kind: 'server-error' }) },
  { label: 'permission lost', answer: () => ({ kind: 'forbidden' }) },
  { label: 'key expired', answer: () => ({ kind: 'key-expired' }) },
];
