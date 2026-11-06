import { StrictMode, type ReactElement } from 'react';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ refresh: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ refresh: nav.refresh }) }));
import { WarehousePage } from '@/app/(app)/warehouses/[id]/WarehousePage';
import type { StorageLocation, Warehouse } from '@/lib/api/warehouses';
import { empty, json, problem, refused, renderWired, stubFetch } from './harness';

// Revision 7 §2 step 4.6, warehouses-ux-copy.md B-7 and STATES.md ST-9b: Delete warehouse (page action, live only) and
// Delete location (live row action). Danger dialog, initial focus Cancel. A refusal shows under "Not deleted" as sent,
// its heading focused; any refusal except P1 removes Delete and leaves Close; P1 (`transaction.contention`) keeps Delete.
// After a 204: the warehouse page stays and turns archived (focus h1, B-7 "After" spoken); a location leaves the live
// view (focus the section heading, "After" spoken, the view re-read). Every other close after an answer re-reads.

afterEach(() => {
  vi.unstubAllGlobals();
  nav.refresh.mockClear();
});

const WID = '6a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';
const MAIN: Warehouse = { id: WID, code: 'MAIN', name: 'Main warehouse', country_code: 'GB', archived_at: null };
const ARCHIVED: Warehouse = { ...MAIN, archived_at: '2026-11-05T10:00:00Z' };
const loc = (id: string, code: string, kind: string, archived_at: string | null = null): StorageLocation => ({ id, code, kind, archived_at });
const A1 = loc('11111111-1111-4111-8111-111111111111', 'A1', 'bin');
const Q1 = loc('22222222-2222-4222-8222-222222222222', 'Q1', 'quarantine');
const B1 = loc('44444444-4444-4444-8444-444444444444', 'B1', 'shelf', '2026-04-01T09:00:00Z');
const coll = (items: StorageLocation[]) => ({ items, next_cursor: null, previous_cursor: null, tenant_has_rows: true });
const region = () => document.querySelector<HTMLElement>('section > [role="status"]')!;
const dialog = () => screen.getByRole('dialog');
/** `TransactionContention::MESSAGE` (backend/src/Platform/Http/Problem/TransactionContention.php:32), RED L-4. */
const CONTENTION = 'Some of the same records were being changed at the same moment. Nothing was saved; try again.';
const W7 = "This warehouse still has 1 open purchase order, so it can't be deleted while it has any. Drafts count as open.";
const L8 = "This location still holds stock, so it can't be deleted until no stock is left in it.";

const showWarehouse = (o: { w?: Warehouse; canDelete?: boolean; canRestore?: boolean } = {}) =>
  renderWired(<WarehousePage initial={o.w ?? MAIN} canEdit={false} canRestore={o.canRestore ?? true} canDelete={o.canDelete ?? true} />);

describe('/warehouses/[id] — Delete warehouse (B-7, step 4.6)', () => {
  it('offered on a live warehouse to a DeleteWarehouse holder only, never on an archived one', () => {
    const a = showWarehouse({ canDelete: false });
    expect(screen.queryByRole('button', { name: 'Delete warehouse' })).toBeNull();
    a.unmount();
    const b = showWarehouse({ w: ARCHIVED });
    expect(screen.queryByRole('button', { name: 'Delete warehouse' })).toBeNull();
    b.unmount();
    showWarehouse();
    expect(screen.getByRole('button', { name: 'Delete warehouse' })).toBeInTheDocument();
  });

  it('the dialog: "Delete {code} — {name}?", B-7 body, focus on Cancel; Cancel sends nothing and re-reads nothing', async () => {
    const calls = stubFetch([]);
    const u = userEvent.setup();
    showWarehouse();
    await u.click(screen.getByRole('button', { name: 'Delete warehouse' }));
    expect(within(dialog()).getByRole('heading', { name: 'Delete MAIN — Main warehouse?' })).toBeInTheDocument();
    expect(dialog()).toHaveTextContent("It moves to the archived warehouses. Its locations can't be used, and returns of goods shipped from it can't be recorded, until it's restored.");
    await waitFor(() => expect(within(dialog()).getByRole('button', { name: 'Cancel' })).toHaveFocus());
    await u.click(within(dialog()).getByRole('button', { name: 'Cancel' }));
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(calls).toEqual([]);
  });

  it('a 204: DELETE with no body and no key, the re-read turns the page archived, focus on the h1, "After" spoken, one refresh', async () => {
    const calls = stubFetch([empty(204), json(200, ARCHIVED)]);
    const u = userEvent.setup();
    showWarehouse();
    await u.click(screen.getByRole('button', { name: 'Delete warehouse' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete warehouse' }));
    await waitFor(() => expect(region()).toHaveTextContent("MAIN was deleted. It's now in the archived warehouses."));
    expect(calls.map((c) => c.method)).toEqual(['DELETE', 'GET']);
    expect(calls[0]!.url).toContain(`/bff/api/warehouses/${WID}`);
    expect(calls[0]!.body).toBeNull();
    expect(calls[0]!.headers.get('idempotency-key')).toBeNull();
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(screen.getByRole('heading', { level: 1 })).toHaveFocus();
    expect(screen.getByText(/This warehouse was archived on/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Restore warehouse' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Delete warehouse' })).toBeNull();
    expect(nav.refresh).toHaveBeenCalledTimes(1);
  });

  it('a refusal (W7): "Not deleted" + the sentence as sent, heading focused; Delete removed, only Close; Close re-reads', async () => {
    const calls = stubFetch([refused('warehouse.has_open_purchase_orders', W7), json(200, MAIN)]);
    const u = userEvent.setup();
    showWarehouse();
    await u.click(screen.getByRole('button', { name: 'Delete warehouse' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete warehouse' }));
    await waitFor(() => expect(within(dialog()).getByText('Not deleted')).toHaveFocus());
    expect(dialog()).toHaveTextContent(W7);
    expect(within(dialog()).queryByRole('button', { name: 'Delete warehouse' })).toBeNull();
    expect(within(dialog()).queryByRole('button', { name: 'Cancel' })).toBeNull();
    await u.click(within(dialog()).getByRole('button', { name: 'Close' }));
    expect(screen.queryByRole('dialog')).toBeNull();
    await waitFor(() => expect(calls.map((c) => c.method)).toEqual(['DELETE', 'GET']));
  });

  it('a refusal after which the re-read finds the warehouse archived (W6, deleted elsewhere) turns the page archived', async () => {
    stubFetch([refused('warehouse.is_archived', "This warehouse is already archived. It's in the archived warehouses."), json(200, ARCHIVED)]);
    const u = userEvent.setup();
    showWarehouse();
    await u.click(screen.getByRole('button', { name: 'Delete warehouse' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete warehouse' }));
    await waitFor(() => expect(within(dialog()).getByText('Not deleted')).toHaveFocus());
    await u.click(within(dialog()).getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(screen.getByText(/This warehouse was archived on/)).toBeInTheDocument());
    // RED H-1: the re-read unmounted the Delete warehouse button focus had returned to; the h1 takes it.
    expect(screen.getByRole('heading', { level: 1 })).toHaveFocus();
    expect(nav.refresh).toHaveBeenCalledTimes(1);
  });

  it('interrupted, then Escape, then the re-read finds it archived (the press committed): the h1 takes focus (RED H-1)', async () => {
    stubFetch([problem(503, 'x'), json(200, ARCHIVED)]);
    const u = userEvent.setup();
    showWarehouse();
    await u.click(screen.getByRole('button', { name: 'Delete warehouse' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete warehouse' }));
    await waitFor(() => expect(dialog()).toHaveTextContent("We can't tell whether MAIN was deleted."));
    await u.keyboard('{Escape}');
    await waitFor(() => expect(screen.getByText(/This warehouse was archived on/)).toBeInTheDocument());
    expect(screen.getByRole('heading', { level: 1 })).toHaveFocus();
    expect(nav.refresh).toHaveBeenCalledTimes(1);
  });

  it('a 204 whose re-read fails: the page says so, and offers no Delete warehouse and no location actions (RED M-1)', async () => {
    const calls = stubFetch([empty(204), problem(503, 'x')]);
    const u = userEvent.setup();
    renderWired(<WarehousePage initial={MAIN} canEdit canRestore canDelete locations={{ initial: { kind: 'ok', locations: [A1] }, canEdit: true, canRestore: true, canCreate: true, canDelete: true }} />);
    await u.click(screen.getByRole('button', { name: 'Delete warehouse' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete warehouse' }));
    await waitFor(() => expect(region()).toHaveTextContent("MAIN was deleted. It's now in the archived warehouses."));
    await waitFor(() => expect(screen.getByRole('button', { name: /Retry|Try again/ })).toBeInTheDocument());
    expect(calls.map((c) => c.method)).toEqual(['DELETE', 'GET']);
    expect(screen.queryByRole('button', { name: 'Delete warehouse' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Save' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Delete A1' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Edit A1' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Add location' })).toBeNull();
  });

  it('a 404 shows "We couldn\u2019t find that." and keeps Delete; a 500 says "Something went wrong" with the interrupted sentence (RED L-1)', async () => {
    stubFetch([problem(404, 'not-found'), problem(500, 'x')]);
    const u = userEvent.setup();
    showWarehouse();
    await u.click(screen.getByRole('button', { name: 'Delete warehouse' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete warehouse' }));
    await waitFor(() => expect(dialog()).toHaveTextContent("We couldn't find that."));
    expect(within(dialog()).getByText('Not deleted')).toBeInTheDocument();
    await u.click(within(dialog()).getByRole('button', { name: 'Delete warehouse' }));
    await waitFor(() => expect(dialog()).toHaveTextContent('Something went wrong on our side.'));
    expect(dialog()).toHaveTextContent("We can't tell whether MAIN was deleted. Press Delete warehouse to try again.");
    expect(within(dialog()).queryByText("We couldn't reach the server.")).toBeNull();
  });

  it('contention (P1): the sentence shows and Delete stays; pressing it again sends a second DELETE', async () => {
    const calls = stubFetch([refused('transaction.contention', CONTENTION), empty(204), json(200, ARCHIVED)]);
    const u = userEvent.setup();
    showWarehouse();
    await u.click(screen.getByRole('button', { name: 'Delete warehouse' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete warehouse' }));
    await waitFor(() => expect(within(dialog()).getByText('Not deleted')).toHaveFocus());
    expect(dialog()).toHaveTextContent(CONTENTION);
    expect(within(dialog()).queryByRole('button', { name: 'Close' })).toBeNull();
    await u.click(within(dialog()).getByRole('button', { name: 'Delete warehouse' }));
    await waitFor(() => expect(region()).toHaveTextContent("MAIN was deleted. It's now in the archived warehouses."));
    expect(calls.map((c) => c.method)).toEqual(['DELETE', 'DELETE', 'GET']);
  });

  it('interrupted: "We can’t tell whether MAIN was deleted…", Delete stays; Escape closes and re-reads', async () => {
    const calls = stubFetch([problem(503, 'x'), json(200, MAIN)]);
    const u = userEvent.setup();
    showWarehouse();
    await u.click(screen.getByRole('button', { name: 'Delete warehouse' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete warehouse' }));
    await waitFor(() => expect(dialog()).toHaveTextContent("We can't tell whether MAIN was deleted. Press Delete warehouse to try again."));
    expect(within(dialog()).getByRole('button', { name: 'Delete warehouse' })).toBeInTheDocument();
    await u.keyboard('{Escape}');
    expect(screen.queryByRole('dialog')).toBeNull();
    await waitFor(() => expect(calls.map((c) => c.method)).toEqual(['DELETE', 'GET']));
  });

  it('an unreachable delete (offline) says the same, and Delete stays', async () => {
    const offline = () => {
      throw new TypeError('Failed to fetch');
    };
    stubFetch([offline]);
    const u = userEvent.setup();
    showWarehouse();
    await u.click(screen.getByRole('button', { name: 'Delete warehouse' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete warehouse' }));
    await waitFor(() => expect(dialog()).toHaveTextContent("We couldn't reach the server."));
    expect(dialog()).toHaveTextContent("We can't tell whether MAIN was deleted.");
    expect(within(dialog()).getByRole('button', { name: 'Delete warehouse' })).toBeInTheDocument();
  });

  it('a 403 names Delete and restore warehouses (B-8)', async () => {
    stubFetch([problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    showWarehouse();
    await u.click(screen.getByRole('button', { name: 'Delete warehouse' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete warehouse' }));
    await waitFor(() => expect(within(dialog()).getByText('Not deleted')).toHaveFocus());
    expect(dialog()).toHaveTextContent('This needs the Delete and restore warehouses permission, which you no longer have. Your workspace administrator can grant it.');
  });
});

function showSection(o: { w?: Warehouse; canDelete?: boolean } = {}) {
  const locations = { initial: { kind: 'ok' as const, locations: [A1, Q1] }, canEdit: false, canRestore: true, canCreate: false, canDelete: o.canDelete ?? true };
  return renderWired(<WarehousePage initial={o.w ?? MAIN} canEdit={false} canRestore={false} locations={locations} />);
}
const section = () => screen.getByRole('heading', { level: 2 }).closest('section')!;

describe('/warehouses/[id] — Delete location (B-7, step 4.6)', () => {
  it('Delete per live row for a DeleteStorageLocation holder only; none on an archived warehouse; none in the Archived view', async () => {
    const a = showSection({ canDelete: false });
    expect(screen.queryByRole('button', { name: 'Delete A1' })).toBeNull();
    a.unmount();
    const b = showSection({ w: ARCHIVED });
    expect(screen.queryByRole('button', { name: 'Delete A1' })).toBeNull();
    b.unmount();
    stubFetch([json(200, coll([B1]))]);
    const u = userEvent.setup();
    showSection();
    expect(screen.getByRole('button', { name: 'Delete A1' })).toBeInTheDocument();
    await u.click(within(section()).getByRole('button', { name: 'Archived' }));
    await waitFor(() => expect(screen.getByRole('heading', { level: 2, name: 'Archived locations' })).toBeInTheDocument());
    expect(screen.queryByRole('button', { name: 'Delete B1' })).toBeNull();
  });

  it('a 204: DELETE on the location, focus the section heading, "After" spoken, the view re-read (the row leaves it)', async () => {
    const calls = stubFetch([empty(204), json(200, coll([Q1]))]);
    const u = userEvent.setup();
    showSection();
    await u.click(screen.getByRole('button', { name: 'Delete A1' }));
    expect(within(dialog()).getByRole('heading', { name: 'Delete location A1?' })).toBeInTheDocument();
    await u.click(within(dialog()).getByRole('button', { name: 'Delete location' }));
    await waitFor(() => expect(region()).toHaveTextContent("A1 was deleted. It's now in this warehouse's archived locations."));
    expect(calls[0]!.method).toBe('DELETE');
    expect(calls[0]!.url).toContain(`/bff/api/warehouses/${WID}/locations/${A1.id}`);
    expect(calls[0]!.headers.get('idempotency-key')).toBeNull();
    await waitFor(() => expect(calls.map((c) => c.method)).toEqual(['DELETE', 'GET']));
    expect(screen.getByRole('heading', { level: 2 })).toHaveFocus();
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Delete A1' })).toBeNull());
  });

  // Mutation M10 (first GREEN): with the re-read held open, focus is on the section heading when "After" is spoken, not on
  // the row's Delete button the dialog would otherwise return focus to (FE-D1: close → focus → announce → refresh).
  it('focus is on the section heading when "After" is spoken, before the re-read answers', async () => {
    stubFetch([empty(204), 'hang']);
    const u = userEvent.setup();
    showSection();
    await u.click(screen.getByRole('button', { name: 'Delete A1' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete location' }));
    await waitFor(() => expect(region()).toHaveTextContent("A1 was deleted."));
    expect(screen.getByRole('button', { name: 'Delete A1' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { level: 2 })).toHaveFocus();
  });

  it('a refusal (L8): "Not deleted" as sent, only Close; Close re-reads the view', async () => {
    const calls = stubFetch([refused('storage_location.holds_stock', L8), json(200, coll([A1, Q1]))]);
    const u = userEvent.setup();
    showSection();
    await u.click(screen.getByRole('button', { name: 'Delete A1' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete location' }));
    await waitFor(() => expect(within(dialog()).getByText('Not deleted')).toHaveFocus());
    expect(dialog()).toHaveTextContent(L8);
    expect(within(dialog()).queryByRole('button', { name: 'Delete location' })).toBeNull();
    await u.click(within(dialog()).getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(calls.map((c) => c.method)).toEqual(['DELETE', 'GET']));
    expect(region()).not.toHaveTextContent('was deleted');
  });

  it('contention (P1) keeps Delete location; a second press deletes', async () => {
    const calls = stubFetch([refused('transaction.contention', CONTENTION), empty(204), json(200, coll([Q1]))]);
    const u = userEvent.setup();
    showSection();
    await u.click(screen.getByRole('button', { name: 'Delete A1' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete location' }));
    await waitFor(() => expect(dialog()).toHaveTextContent(CONTENTION));
    expect(within(dialog()).queryByRole('button', { name: 'Close' })).toBeNull();
    await u.click(within(dialog()).getByRole('button', { name: 'Delete location' }));
    await waitFor(() => expect(region()).toHaveTextContent("A1 was deleted."));
    await waitFor(() => expect(calls.map((c) => c.method)).toEqual(['DELETE', 'DELETE', 'GET']));
  });

  it('a 403 names Delete and restore locations (B-8; RED L-1)', async () => {
    stubFetch([problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    showSection();
    await u.click(screen.getByRole('button', { name: 'Delete A1' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete location' }));
    await waitFor(() => expect(dialog()).toHaveTextContent('This needs the Delete and restore locations permission, which you no longer have. Your workspace administrator can grant it.'));
  });

  it('a successful delete clears an earlier Restore location refusal (D-8; RED L-3)', async () => {
    const msg = 'Another location in this warehouse already uses this code.';
    stubFetch([json(200, coll([B1])), refused('storage_location.code_is_unique_per_warehouse', msg), json(200, coll([B1])), json(200, coll([B1])), json(200, coll([A1, Q1])), empty(204), json(200, coll([Q1]))]);
    const u = userEvent.setup();
    showSection();
    await u.click(within(section()).getByRole('button', { name: 'Archived' }));
    await u.click(await screen.findByRole('button', { name: 'Restore location B1' }));
    await waitFor(() => expect(section()).toHaveTextContent(msg));
    await u.click(within(section()).getByRole('button', { name: 'Back to locations' }));
    await u.click(await screen.findByRole('button', { name: 'Delete A1' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete location' }));
    await waitFor(() => expect(region()).toHaveTextContent('A1 was deleted.'));
    expect(section()).not.toHaveTextContent(msg);
  });

  it('Cancel returns focus to the row\'s Delete button and sends nothing', async () => {
    const calls = stubFetch([]);
    const u = userEvent.setup();
    showSection();
    await u.click(screen.getByRole('button', { name: 'Delete A1' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Cancel' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Delete A1' })).toHaveFocus());
    expect(calls).toEqual([]);
  });
});

// RED M-3 / M-4: FE-D1 order on the warehouse delete, plain and under StrictMode. A `focusin` listener records the DOM at
// the moment the h1 takes focus (the fe-d1-order.test.tsx probe): the "After" sentence must not exist yet and no dialog
// may be in the document. Then a 409 → Close → re-read, under both modes.
for (const strict of [false, true]) {
  const wrap = (ui: ReactElement) => (strict ? <StrictMode>{ui}</StrictMode> : ui);
  const suffix = strict ? ' (StrictMode)' : '';
  describe(`Delete warehouse — FE-D1 order${suffix}`, () => {
    it('204: the dialog closes, the h1 takes focus, THEN "After" is written; one refresh', async () => {
      stubFetch([empty(204), json(200, ARCHIVED)]);
      const u = userEvent.setup();
      renderWired(wrap(<WarehousePage initial={MAIN} canEdit={false} canRestore canDelete />));
      await u.click(screen.getByRole('button', { name: 'Delete warehouse' }));
      const text = "MAIN was deleted. It's now in the archived warehouses.";
      const atFocus: { text: boolean; dialog: boolean }[] = [];
      const onFocusIn = (e: FocusEvent) => {
        if ((e.target as HTMLElement).tagName === 'H1') atFocus.push({ text: (document.body.textContent ?? '').includes(text), dialog: document.querySelector('[role="dialog"]') !== null });
      };
      document.addEventListener('focusin', onFocusIn);
      await u.click(within(dialog()).getByRole('button', { name: 'Delete warehouse' }));
      await waitFor(() => expect(region()).toHaveTextContent(text));
      document.removeEventListener('focusin', onFocusIn);
      expect(atFocus[0]).toEqual({ text: false, dialog: false });
      expect(screen.getByRole('heading', { level: 1 })).toHaveFocus();
      expect(nav.refresh).toHaveBeenCalledTimes(1);
    });

    it('409 → Close → re-read: the banner heading had focus; after Close the re-read ran once', async () => {
      const calls = stubFetch([refused('warehouse.holds_stock', 'This warehouse still holds stock in 2 locations, so it can\'t be deleted until no stock is left in it.'), json(200, MAIN)]);
      const u = userEvent.setup();
      renderWired(wrap(<WarehousePage initial={MAIN} canEdit={false} canRestore canDelete />));
      await u.click(screen.getByRole('button', { name: 'Delete warehouse' }));
      await u.click(within(dialog()).getByRole('button', { name: 'Delete warehouse' }));
      await waitFor(() => expect(within(dialog()).getByText('Not deleted')).toHaveFocus());
      await u.click(within(dialog()).getByRole('button', { name: 'Close' }));
      await waitFor(() => expect(calls.map((c) => c.method)).toEqual(['DELETE', 'GET']));
      await new Promise((r) => setTimeout(r, 50));
      expect(calls).toHaveLength(2);
      expect(screen.getByRole('button', { name: 'Delete warehouse' })).toHaveFocus();
    });
  });
}
