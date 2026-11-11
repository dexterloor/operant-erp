import { StrictMode, type ReactElement } from 'react';
import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ refresh: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ refresh: nav.refresh }) }));
import { WarehousePage } from '@/app/(app)/warehouses/[id]/WarehousePage';
import type { StorageLocation, Warehouse } from '@/lib/api/warehouses';
import { empty, json, problem, refused, renderWired, stubFetch, textsOf } from './harness';

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

  // Commit 0a (items-i3-red.md M-1, probe P-1; the final RED's races W-A / W-Afail): a Save whose re-read is still in
  // flight when the warehouse is deleted asked for that reading before the delete, so it must not turn the page live
  // again. The 204 raises what is on screen to the delete's re-read stamp, even when that re-read fails.
  const saveThenDelete = async (deleteReread: Response) => {
    let release!: (r: Response) => void;
    const held = new Promise<Response>((res) => (release = res));
    const calls = stubFetch([json(200, { ...MAIN, name: 'Main warehousex' }), () => held, empty(204), deleteReread]);
    const u = userEvent.setup();
    renderWired(<WarehousePage initial={MAIN} canEdit canRestore canDelete />);
    await u.type(screen.getByRole('textbox', { name: 'Name' }), 'x');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(calls).toHaveLength(2));
    await u.click(screen.getByRole('button', { name: 'Delete warehouse' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete warehouse' }));
    await waitFor(() => expect(region()).toHaveTextContent("MAIN was deleted. It's now in the archived warehouses."));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await waitFor(() => expect(nav.refresh).toHaveBeenCalledTimes(1));
    release(json(200, { ...MAIN, name: 'Main warehousex' }));
    // The Save is over once the form has refreshed the router (`onSaved`, after the action resolved); one more timer
    // turn inside act lets any region-error timer it set render.
    await waitFor(() => expect(nav.refresh).toHaveBeenCalledTimes(2));
    await act(async () => {
      await new Promise((t) => setTimeout(t, 20));
    });
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET', 'DELETE', 'GET']);
  };

  it('an older Save re-read landing after a 204 does not turn the page live: archived banner stays, no Delete warehouse, no form (0a, P-1)', async () => {
    await saveThenDelete(json(200, { ...ARCHIVED, name: 'Main warehousex' }));
    expect(screen.getByText(/This warehouse was archived on/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Delete warehouse' })).toBeNull();
    expect(screen.queryByRole('textbox', { name: 'Name' })).toBeNull();
  });

  it("…and the same when the delete's own re-read fails: no Delete warehouse, no form, the region error shown (0a, the 204 floor)", async () => {
    await saveThenDelete(json(503, {}));
    expect(screen.queryByRole('button', { name: 'Delete warehouse' })).toBeNull();
    expect(screen.queryByRole('textbox', { name: 'Name' })).toBeNull();
    expect(screen.getByText("We couldn't load this warehouse again, so what's shown may be out of date.")).toBeInTheDocument();
  });

  // RED i3-0a0b H-1: a Save whose answer arrives after the 204 was processed before the delete (a later Save would
  // have been refused), so its 200 body is the warehouse before the delete. When the Save's re-read fails, that body
  // must not turn the page live again, whether the delete's own re-read has answered or is still in flight.
  it('a Save answering after the 204, whose re-read fails, does not turn the page live: archived banner stays, no Delete warehouse, no form (0a, RED H-1)', async () => {
    let release!: (r: Response) => void;
    const held = new Promise<Response>((res) => (release = res));
    const calls = stubFetch([() => held, empty(204), json(200, ARCHIVED), json(503, {})]);
    const u = userEvent.setup();
    renderWired(<WarehousePage initial={MAIN} canEdit canRestore canDelete />);
    await u.type(screen.getByRole('textbox', { name: 'Name' }), 'x');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await u.click(screen.getByRole('button', { name: 'Delete warehouse' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete warehouse' }));
    await waitFor(() => expect(screen.getByText(/This warehouse was archived on/)).toBeInTheDocument());
    await act(async () => {
      release(json(200, { ...MAIN, name: 'Main warehousex' }));
      await new Promise((t) => setTimeout(t, 50));
    });
    await act(async () => {
      await new Promise((t) => setTimeout(t, 50));
    });
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'DELETE', 'GET', 'GET']);
    expect(screen.getByText(/This warehouse was archived on/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Delete warehouse' })).toBeNull();
    expect(screen.queryByRole('textbox', { name: 'Name' })).toBeNull();
  });

  it("…and the same when the Save answers while the delete's re-read is in flight: that re-read's archived answer is still shown (0a, RED H-1)", async () => {
    let releaseSave!: (r: Response) => void;
    let releaseReread!: (r: Response) => void;
    const save = new Promise<Response>((res) => (releaseSave = res));
    const reread = new Promise<Response>((res) => (releaseReread = res));
    const calls = stubFetch([() => save, empty(204), () => reread, json(503, {})]);
    const u = userEvent.setup();
    renderWired(<WarehousePage initial={MAIN} canEdit canRestore canDelete />);
    await u.type(screen.getByRole('textbox', { name: 'Name' }), 'x');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await u.click(screen.getByRole('button', { name: 'Delete warehouse' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete warehouse' }));
    await waitFor(() => expect(calls).toHaveLength(3));
    await act(async () => {
      releaseSave(json(200, { ...MAIN, name: 'Main warehousex' }));
      await new Promise((t) => setTimeout(t, 50));
    });
    await act(async () => {
      releaseReread(json(200, ARCHIVED));
      await new Promise((t) => setTimeout(t, 50));
    });
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'DELETE', 'GET', 'GET']);
    expect(screen.getByText(/This warehouse was archived on/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Delete warehouse' })).toBeNull();
    expect(screen.queryByRole('textbox', { name: 'Name' })).toBeNull();
  });

  // RED i3-0a0b L-1: the 204 floor is raised before the delete's re-read is sent. A Save re-read landing while that
  // re-read is in flight is older, so the form must not come back behind the open dialog.
  it("an older Save re-read landing while the delete's re-read is in flight does not bring the form back (0a, the 204 floor's timing)", async () => {
    let releaseSave!: (r: Response) => void;
    let releaseReread!: (r: Response) => void;
    const saveReread = new Promise<Response>((res) => (releaseSave = res));
    const deleteReread = new Promise<Response>((res) => (releaseReread = res));
    const calls = stubFetch([json(200, { ...MAIN, name: 'Main warehousex' }), () => saveReread, empty(204), () => deleteReread]);
    const u = userEvent.setup();
    renderWired(<WarehousePage initial={MAIN} canEdit canRestore canDelete />);
    await u.type(screen.getByRole('textbox', { name: 'Name' }), 'x');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(calls).toHaveLength(2));
    await u.click(screen.getByRole('button', { name: 'Delete warehouse' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete warehouse' }));
    await waitFor(() => expect(calls).toHaveLength(4));
    await act(async () => {
      releaseSave(json(200, { ...MAIN, name: 'Main warehousex' }));
      await new Promise((t) => setTimeout(t, 50));
    });
    expect(screen.queryByRole('textbox', { name: 'Name', hidden: true })).toBeNull();
    await act(async () => {
      releaseReread(json(503, {}));
      await new Promise((t) => setTimeout(t, 50));
    });
    expect(screen.queryByRole('button', { name: 'Delete warehouse' })).toBeNull();
    expect(screen.queryByRole('textbox', { name: 'Name' })).toBeNull();
  });

  // Moved deliberately in commit 0b (items-i3-design.md Revision 1 — FINAL §0b; UX items-i3-ux.md F-3; ruling 1(b)): a
  // 404 used to keep Delete (4.6 D-3); a repeat cannot succeed, so it now leaves Close only. Close re-reads the warehouse,
  // so the 500 half needs a second open. Stub order (final RED L-7): the 404, Close's re-read, the second dialog's 500.
  it('a 404 shows "We couldn\u2019t find that." and leaves Close only; Close re-reads; a 500 (in a second dialog) says "Something went wrong" with the interrupted sentence (0b; RED L-1)', async () => {
    const calls = stubFetch([problem(404, 'not-found'), json(200, MAIN), problem(500, 'x')]);
    const u = userEvent.setup();
    showWarehouse();
    await u.click(screen.getByRole('button', { name: 'Delete warehouse' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete warehouse' }));
    await waitFor(() => expect(dialog()).toHaveTextContent("We couldn't find that."));
    await waitFor(() => expect(within(dialog()).getByText('Not deleted')).toHaveFocus());
    expect(within(dialog()).getByText('Not deleted')).toBeInTheDocument();
    expect(within(dialog()).queryByRole('button', { name: 'Delete warehouse' })).toBeNull();
    expect(within(dialog()).queryByRole('button', { name: 'Cancel' })).toBeNull();
    await u.click(within(dialog()).getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(calls.map((c) => c.method)).toEqual(['DELETE', 'GET']));
    expect(screen.queryByRole('dialog')).toBeNull();
    await u.click(await screen.findByRole('button', { name: 'Delete warehouse' }));
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
    // Ruling 1(b), second half (0b): a 403 keeps Delete (4.6 D-3); only a 404 leaves Close only.
    expect(within(dialog()).getByRole('button', { name: 'Delete warehouse' })).toBeInTheDocument();
  });

  // Warehouse re-read RED L-1: the earlier re-read error is cleared by the Delete paths' commits too, not by Save alone.
  const REREAD_FAILED = "We couldn't load this warehouse again, so what's shown may be out of date.";
  const editable = () => renderWired(<WarehousePage initial={MAIN} canEdit canRestore canDelete />);
  const saveWithFailedReread = async (u: ReturnType<typeof userEvent.setup>) => {
    await u.type(screen.getByRole('textbox', { name: 'Name' }), 'x');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    expect(await screen.findByText(REREAD_FAILED)).toBeInTheDocument();
  };

  it('a 204 whose re-read succeeds clears an earlier re-read error; focus on the h1 (warehouse re-read RED L-1)', async () => {
    stubFetch([json(200, { ...MAIN, name: 'Mainx' }), json(503, {}), empty(204), json(200, { ...ARCHIVED, name: 'Mainx' })]);
    const u = userEvent.setup();
    editable();
    await saveWithFailedReread(u);
    await u.click(screen.getByRole('button', { name: 'Delete warehouse' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete warehouse' }));
    await waitFor(() => expect(screen.getByText(/This warehouse was archived on/)).toBeInTheDocument());
    expect(screen.queryByText(REREAD_FAILED)).toBeNull();
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveFocus());
  });

  it("a refusal's Close re-read that succeeds clears an earlier re-read error (warehouse re-read RED L-1)", async () => {
    stubFetch([json(200, { ...MAIN, name: 'Mainx' }), json(503, {}), refused('warehouse.is_archived', "This warehouse is already archived. It's in the archived warehouses."), json(200, ARCHIVED)]);
    const u = userEvent.setup();
    editable();
    await saveWithFailedReread(u);
    await u.click(screen.getByRole('button', { name: 'Delete warehouse' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete warehouse' }));
    await u.click(await within(dialog()).findByRole('button', { name: 'Close' }));
    await waitFor(() => expect(screen.getByText(/This warehouse was archived on/)).toBeInTheDocument());
    expect(screen.queryByText(REREAD_FAILED)).toBeNull();
  });

  // The close re-read's stale guard (warehouse re-read RED M-2, X3): a Close re-read still waiting when a newer commit
  // (a Save) lands is dropped, whether it answers the old warehouse or fails.
  const overtakenClose = async (release: () => Response) => {
    let releaseHeld!: (r: Response) => void;
    const held = new Promise<Response>((res) => (releaseHeld = res));
    stubFetch([refused('warehouse.has_open_purchase_orders', W7), () => held, json(200, { ...MAIN, name: 'Mainy' }), json(200, { ...MAIN, name: 'Mainy' })]);
    const u = userEvent.setup();
    editable();
    await u.click(screen.getByRole('button', { name: 'Delete warehouse' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete warehouse' }));
    await u.click(await within(dialog()).findByRole('button', { name: 'Close' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    const name = screen.getByRole('textbox', { name: 'Name' });
    await u.clear(name);
    await u.type(name, 'Mainy');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('MAIN — Mainy'));
    await act(async () => {
      releaseHeld(release());
      await new Promise((t) => setTimeout(t, 50));
    });
  };

  it('an overtaken Close re-read that answers the old warehouse is dropped: the saved name stays (X3)', async () => {
    await overtakenClose(() => json(200, MAIN));
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('MAIN — Mainy');
    expect(screen.getByRole('textbox', { name: 'Name' })).toHaveValue('Mainy');
  });

  it('an overtaken Close re-read that fails is dropped: no region error over the saved warehouse (X3)', async () => {
    await overtakenClose(() => json(503, {}));
    expect(screen.queryByText(REREAD_FAILED)).toBeNull();
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('MAIN — Mainy');
  });

  // RED-2 M-1: only a commit of NEWER evidence drops a page-owned re-read; an older Retry landing first must not.
  const twoReads = async (first: 'retry' | 'close') => {
    let releaseRetry!: (r: Response) => void;
    let releaseClose!: (r: Response) => void;
    const retryHeld = new Promise<Response>((res) => (releaseRetry = res));
    const closeHeld = new Promise<Response>((res) => (releaseClose = res));
    stubFetch([json(200, { ...MAIN, name: 'Mainx' }), json(503, {}), () => retryHeld, refused('warehouse.is_archived', "This warehouse is already archived. It's in the archived warehouses."), () => closeHeld]);
    const u = userEvent.setup();
    editable();
    await saveWithFailedReread(u);
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await u.click(screen.getByRole('button', { name: 'Delete warehouse' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete warehouse' }));
    await u.click(await within(dialog()).findByRole('button', { name: 'Close' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    const retryAnswers = () => releaseRetry(json(200, { ...MAIN, name: 'Mainx' }));
    const closeAnswers = () => releaseClose(json(200, { ...ARCHIVED, name: 'Mainx' }));
    for (const release of first === 'retry' ? [retryAnswers, closeAnswers] : [closeAnswers, retryAnswers]) {
      await act(async () => {
        release();
        await new Promise((t) => setTimeout(t, 50));
      });
    }
  };

  it('an older Retry that lands first does not drop the newer Close re-read: the page turns archived (RED-2 M-1)', async () => {
    await twoReads('retry');
    expect(screen.getByText(/This warehouse was archived on/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Delete warehouse' })).toBeNull();
    expect(screen.getByRole('heading', { level: 1 })).toHaveFocus();
  });

  it('a newer Close re-read that lands first is not undone by the older Retry (RED-2 M-1, reverse order)', async () => {
    await twoReads('close');
    expect(screen.getByText(/This warehouse was archived on/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Delete warehouse' })).toBeNull();
  });

  // The warehouse twin of i3-c1 RED L-3 (a shipped defect): someone restores the warehouse between the 204 and the
  // page's re-read. The re-read is committed (it is not dropped) and clears the 204 fact; the close must not set it again.
  it('a 204 whose re-read reads the warehouse live (restored in between): the page is live, offers its actions, and says it has since been restored (S-2)', async () => {
    stubFetch([empty(204), json(200, MAIN)]);
    const u = userEvent.setup();
    renderWired(<WarehousePage initial={MAIN} canEdit canRestore canDelete locations={{ initial: { kind: 'ok', locations: [A1] }, canEdit: true, canRestore: true, canCreate: true, canDelete: true }} />);
    const spoken = textsOf(region());
    await u.click(screen.getByRole('button', { name: 'Delete warehouse' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete warehouse' }));
    // S-2 (items-i4-ux.md "S-2 sentences", U4-26; moved deliberately from the archived "After").
    await waitFor(() => expect(region().textContent).toBe('MAIN was deleted, and has since been restored.'));
    spoken.stop();
    expect(spoken.texts.filter((t) => t !== '')).toEqual(['MAIN was deleted, and has since been restored.']);
    await act(async () => {
      await new Promise((t) => setTimeout(t, 50));
    });
    expect(screen.queryByText(/This warehouse was archived on/)).toBeNull();
    expect(screen.getByRole('button', { name: 'Delete warehouse' })).toBeInTheDocument();
    expect(screen.getByRole('textbox', { name: 'Name' })).toBeInTheDocument();
    // i3-c1 fix-RED N-a: the location actions come back with the page.
    expect(screen.getByRole('button', { name: 'Add location' })).toBeInTheDocument();
  });

  // i3-c1 fix-RED L-4 (a shipped defect): "After" names the code the page shows, the code last committed, not the one in
  // the render where Delete was pressed. W2: renamed elsewhere, the 204's re-read brings the new code. W3: a Save renaming
  // the code lands while the delete is in flight. And the fix-RED L-1 twin: a dropped reading does not move it.
  it('renamed elsewhere: the 204 re-read commits the new code, and "After" names it (W2)', async () => {
    stubFetch([empty(204), json(200, { ...ARCHIVED, code: 'MAIN2' })]);
    const u = userEvent.setup();
    showWarehouse();
    await u.click(screen.getByRole('button', { name: 'Delete warehouse' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete warehouse' }));
    await waitFor(() => expect(region()).toHaveTextContent(/was deleted/));
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('MAIN2 — Main warehouse');
    expect(region().textContent).toBe("MAIN2 was deleted. It's now in the archived warehouses.");
  });

  const heldReply = () => {
    let release!: (r: Response) => void;
    const p = new Promise<Response>((res) => (release = res));
    return { reply: () => p, release: (r: Response) => release(r) };
  };
  const pause = () =>
    act(async () => {
      await new Promise((t) => setTimeout(t, 50));
    });

  it('a Save renaming the code lands while the delete is in flight: "After" names the code on screen (W3)', async () => {
    const patch = heldReply();
    const del = heldReply();
    stubFetch([patch.reply, del.reply, json(200, { ...MAIN, code: 'M2' }), json(200, { ...ARCHIVED, code: 'M2' })]);
    const u = userEvent.setup();
    editable();
    const code = screen.getByRole('textbox', { name: 'Code' });
    await u.clear(code);
    await u.type(code, 'M2');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await u.click(screen.getByRole('button', { name: 'Delete warehouse' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete warehouse' }));
    patch.release(json(200, { ...MAIN, code: 'M2' }));
    await pause();
    del.release(empty(204));
    await pause();
    await pause();
    expect(region().textContent).toBe("M2 was deleted. It's now in the archived warehouses.");
  });

  it('a dropped Save re-read (older than the 204) does not change the code "After" speaks (fix-RED L-1 twin)', async () => {
    const patch = heldReply();
    const del = heldReply();
    const saveReread = heldReply();
    const deleteReread = heldReply();
    stubFetch([patch.reply, del.reply, saveReread.reply, deleteReread.reply]);
    const u = userEvent.setup();
    editable();
    const code = screen.getByRole('textbox', { name: 'Code' });
    await u.clear(code);
    await u.type(code, 'M2');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await u.click(screen.getByRole('button', { name: 'Delete warehouse' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete warehouse' }));
    patch.release(json(200, { ...MAIN, code: 'M2' }));
    await pause();
    del.release(empty(204));
    await pause();
    saveReread.release(json(200, { ...MAIN, code: 'M2' }));
    await pause();
    deleteReread.release(json(503, {}));
    await pause();
    await pause();
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('MAIN — Main warehouse');
    expect(region().textContent).toBe("MAIN was deleted. It's now in the archived warehouses.");
  });

  it('a 204 whose re-read fails, then Retry and Restore: the restored warehouse is editable again (the 204 fact is cleared by a later commit)', async () => {
    stubFetch([empty(204), json(503, {}), json(200, ARCHIVED), json(200, MAIN), json(200, MAIN)]);
    const u = userEvent.setup();
    editable();
    await u.click(screen.getByRole('button', { name: 'Delete warehouse' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete warehouse' }));
    expect(await screen.findByText(REREAD_FAILED)).toBeInTheDocument();
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await u.click(await screen.findByRole('button', { name: 'Restore warehouse' }));
    await waitFor(() => expect(screen.queryByText(/This warehouse was archived on/)).toBeNull());
    expect(screen.getByRole('textbox', { name: 'Name' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Delete warehouse' })).toBeInTheDocument();
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
    // Ruling 1(b), second half (0b): a 403 keeps Delete location.
    expect(within(dialog()).getByRole('button', { name: 'Delete location' })).toBeInTheDocument();
  });

  it('a 404 shows "We couldn\u2019t find that." under "Not deleted" and leaves Close only; Close re-reads the view (0b, UX F-3)', async () => {
    const calls = stubFetch([problem(404, 'not-found'), json(200, coll([A1, Q1]))]);
    const u = userEvent.setup();
    showSection();
    await u.click(screen.getByRole('button', { name: 'Delete A1' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete location' }));
    await waitFor(() => expect(dialog()).toHaveTextContent("We couldn't find that."));
    await waitFor(() => expect(within(dialog()).getByText('Not deleted')).toHaveFocus());
    expect(within(dialog()).getByText('Not deleted')).toBeInTheDocument();
    expect(within(dialog()).queryByRole('button', { name: 'Delete location' })).toBeNull();
    expect(within(dialog()).queryByRole('button', { name: 'Cancel' })).toBeNull();
    await u.click(within(dialog()).getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(calls.map((c) => c.method)).toEqual(['DELETE', 'GET']));
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
