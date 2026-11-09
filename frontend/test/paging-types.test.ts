import { describe, expect, it } from 'vitest';
import type { ArchivableListProps, ArchivedView } from '@/app/(app)/ArchivableList';
import { pageQuery, type PageCursor } from '@/lib/bff/reads';

// Items I-1, the type half of the Revision 8 amendments' owed code items (items-i0b-arch.md "Final code items", C-1 and
// C-2 in items-i0b-arch-red.md L-4's form). Each control is a `@ts-expect-error` over a NON-literal object, so it holds
// against excess-property checking being the only thing that refuses it: `npm run typecheck` goes red ("Unused
// '@ts-expect-error' directive") the moment the type stops refusing it. The runtime assertions only keep the values used.

describe('paging types (I-1, C-1 and C-2)', () => {
  it('C-2: a PageCursor carries one cursor at most, by type, even from a non-literal object', () => {
    const both = { after: 'a', before: 'b' };
    // @ts-expect-error — `after` and `before` together is not a PageCursor (C-2: `before?: never` / `after?: never`).
    const bad: PageCursor = both;
    const after: PageCursor = { after: 'a' };
    const before: PageCursor = { before: 'b' };
    expect([bad, after, before]).toHaveLength(3);
    expect(pageQuery(false, { q: '', cursor: after }).toString()).toBe('after=a');
    expect(pageQuery(true, { q: 'X', cursor: before }).toString()).toBe('archived=true&q=X&before=b');
  });

  it("C-1: a whole-view list's as-served Archived view takes no caption, even from a non-literal object", () => {
    const withCaption = { order: 'as-served' as const, caption: 'Most recently archived first', rows: 'plain' as const };
    // @ts-expect-error — A-17 item 7: a whole-view `'as-served'` renders no caption; only the paged variant is given one.
    const view: ArchivedView = withCaption;
    const plain: ArchivedView = { order: 'as-served', rows: 'struck' };
    const sorted: ArchivedView = { order: 'newest-deleted-first', caption: 'c', rows: 'plain' };
    // The whole-view props take the same type: a caption there is refused too.
    const viewProp: ArchivableListProps<{ id: string; archived_at: string | null }>['archivedView'] = plain;
    expect([view, plain, sorted, viewProp]).toHaveLength(4);
  });
});
