import { StrictMode, type ReactElement } from 'react';
import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ refresh: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ refresh: nav.refresh }) }));
import { WarehousePage } from '@/app/(app)/warehouses/[id]/WarehousePage';
import { WAITING } from '@/copy/roles';
import { COULD_NOT_REACH, WENT_WRONG } from '@/copy/headings';
import { LIST_STATES } from '@/copy/shell';
import { DELETE_LOCATION, LOCATIONS_SECTION, RESTORE_LOCATION } from '@/copy/warehouses';
import type { StorageLocation, Warehouse } from '@/lib/api/warehouses';
import { empty, held, json, problem, refused, renderWired, stubFetch, textsOf } from './harness';

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
    const spoken = textsOf(region());
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
    spoken.stop();
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET', 'DELETE', 'GET']);
    // The M-3 twin (not the probe's W-3): the PATCH answered before the delete, but the Save was sent before the person's
    // own 204, so "MAIN was saved." is never spoken over the delete; the refresh above stays.
    expect(region().textContent).toBe("MAIN was deleted. It's now in the archived warehouses.");
    expect(spoken.texts.filter((t) => t !== '')).toEqual(["MAIN was deleted. It's now in the archived warehouses."]);
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

// The item page's late-Save rules, on the warehouse page (item-save-after-delete; po-6-red.md "Spar on the :384
// residual"; items-i4-ux.md M-3 and L-1): a Save sent before a 204 of the person's own Delete leaves the page, focus and
// sentence exactly as after any 204. It speaks no "saved" sentence (W-2, W-3) and puts no region error over the delete
// (W-1); its refusal shows no banner (W-4, W-5: the form is gone with the live state). The 403/404 swap and the refresh
// after "saved" stay. C1–C3 are the controls.
describe("/warehouses/[id] — a Save sent before the person's own Delete, answering after the 204", () => {
  const AFTER = "MAIN was deleted. It's now in the archived warehouses.";
  const SAVED = 'MAIN was saved.';
  const REREAD_FAILED = "We couldn't load this warehouse again, so what's shown may be out of date.";
  const ARCHIVED_BANNER = /This warehouse was archived on/;
  const tick = () =>
    act(async () => {
      await new Promise((t) => setTimeout(t, 50));
    });
  const pressSaveThenDelete = async (u: ReturnType<typeof userEvent.setup>) => {
    renderWired(<WarehousePage initial={MAIN} canEdit canRestore canDelete />);
    const spoken = textsOf(region());
    await u.type(screen.getByRole('textbox', { name: 'Name' }), 'x');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await u.click(screen.getByRole('button', { name: 'Delete warehouse' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete warehouse' }));
    return spoken;
  };

  it('W-1 — answered 200, its re-read fails: no region error over the delete; the h1 keeps focus', async () => {
    const patch = held();
    const calls = stubFetch([patch.reply, empty(204), json(200, ARCHIVED), json(503, {})]);
    const u = userEvent.setup();
    await pressSaveThenDelete(u);
    await waitFor(() => expect(screen.getByText(ARCHIVED_BANNER)).toBeInTheDocument());
    patch.release(json(200, { ...MAIN, name: 'Main warehousex' }));
    await tick();
    await tick();
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'DELETE', 'GET', 'GET']);
    expect(screen.queryByText(REREAD_FAILED)).toBeNull();
    expect(screen.getByRole('heading', { level: 1 })).toHaveFocus();
    expect(screen.getByText(ARCHIVED_BANNER)).toBeInTheDocument();
    expect(screen.queryByRole('textbox', { name: 'Name' })).toBeNull();
  });

  it('W-2 — answered 200, its re-read fails: "MAIN was saved." is never spoken; the region keeps "After"; the refresh stays', async () => {
    const patch = held();
    stubFetch([patch.reply, empty(204), json(200, ARCHIVED), json(503, {})]);
    const u = userEvent.setup();
    const spoken = await pressSaveThenDelete(u);
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    patch.release(json(200, { ...MAIN, name: 'Main warehousex' }));
    await waitFor(() => expect(nav.refresh).toHaveBeenCalledTimes(2));
    await tick();
    spoken.stop();
    expect(spoken.texts).not.toContain(SAVED);
    expect(spoken.texts.filter((t) => t !== '')).toEqual([AFTER]);
    expect(region().textContent).toBe(AFTER);
  });

  it('W-3 — answered 200, its re-read reads the archived warehouse: "MAIN was saved." is never spoken; the refresh stays', async () => {
    const patch = held();
    stubFetch([patch.reply, empty(204), json(200, ARCHIVED), json(200, { ...ARCHIVED, name: 'Main warehousex' })]);
    const u = userEvent.setup();
    const spoken = await pressSaveThenDelete(u);
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    patch.release(json(200, { ...MAIN, name: 'Main warehousex' }));
    await waitFor(() => expect(nav.refresh).toHaveBeenCalledTimes(2));
    await tick();
    spoken.stop();
    expect(spoken.texts).not.toContain(SAVED);
    expect(region().textContent).toBe(AFTER);
    expect(screen.getByRole('heading', { level: 1 })).toHaveFocus();
  });

  it('W-4 (pinned, already true) — refused after the 204: no "Not saved" banner; the h1 keeps focus; "After" only', async () => {
    const patch = held();
    stubFetch([patch.reply, empty(204), json(200, ARCHIVED)]);
    const u = userEvent.setup();
    const spoken = await pressSaveThenDelete(u);
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    patch.release(refused('warehouse.is_archived', 'Fixture: this warehouse is archived.'));
    await tick();
    await tick();
    spoken.stop();
    expect(screen.queryByText('Not saved')).toBeNull();
    expect(screen.queryByText('Fixture: this warehouse is archived.')).toBeNull();
    expect(screen.getByRole('heading', { level: 1 })).toHaveFocus();
    expect(region().textContent).toBe(AFTER);
    expect(spoken.texts.filter((t) => t !== '')).toEqual([AFTER]);
  });

  it('W-5 (pinned, already true) — refused before the 204 answers: the banner is gone after the 204', async () => {
    const patch = held();
    const del = held();
    const calls = stubFetch([patch.reply, del.reply, json(200, ARCHIVED)]);
    const u = userEvent.setup();
    await pressSaveThenDelete(u);
    await waitFor(() => expect(calls).toHaveLength(2));
    patch.release(refused('warehouse.is_archived', 'Fixture: this warehouse is archived.'));
    await tick();
    del.release(empty(204));
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    await tick();
    expect(screen.queryByText('Not saved')).toBeNull();
    expect(screen.getByRole('heading', { level: 1 })).toHaveFocus();
  });

  it("C1 (control) — the delete's own re-read fails too: exactly one region error (the delete's), the same element, whose heading keeps focus", async () => {
    const patch = held();
    const calls = stubFetch([patch.reply, empty(204), json(503, {}), json(503, {})]);
    const u = userEvent.setup();
    await pressSaveThenDelete(u);
    await waitFor(() => expect(screen.getByText(REREAD_FAILED)).toBeInTheDocument());
    const errorHeading = () => screen.getByText(REREAD_FAILED).closest('section')!.querySelector('h2');
    await waitFor(() => expect(errorHeading()).toHaveFocus());
    const shown = errorHeading();
    patch.release(json(200, { ...MAIN, name: 'Main warehousex' }));
    await tick();
    await tick();
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'DELETE', 'GET', 'GET']);
    expect(screen.getAllByText(REREAD_FAILED)).toHaveLength(1);
    expect(errorHeading()).toHaveFocus();
    expect(errorHeading()).toBe(shown);
    expect(screen.queryByRole('textbox', { name: 'Name' })).toBeNull();
  });

  it('C2 (control) — own delete, then Restore, then a Save sent after it whose re-read fails: the region error IS shown, and "MAIN was saved." is spoken', async () => {
    const calls = stubFetch([empty(204), json(200, ARCHIVED), json(200, MAIN), json(200, MAIN), json(200, { ...MAIN, name: 'Main warehousex' }), json(503, {})]);
    const u = userEvent.setup();
    renderWired(<WarehousePage initial={MAIN} canEdit canRestore canDelete />);
    await u.click(screen.getByRole('button', { name: 'Delete warehouse' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete warehouse' }));
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    await u.click(await screen.findByRole('button', { name: 'Restore warehouse' }));
    await waitFor(() => expect(screen.getByRole('textbox', { name: 'Name' })).toBeInTheDocument());
    await u.type(screen.getByRole('textbox', { name: 'Name' }), 'x');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    expect(await screen.findByText(REREAD_FAILED)).toBeInTheDocument();
    await waitFor(() => expect(region()).toHaveTextContent(SAVED));
    expect(calls.map((c) => c.method)).toEqual(['DELETE', 'GET', 'POST', 'GET', 'PATCH', 'GET']);
  });

  it('C3 (control) — answered 200 after the 204, its re-read answered 403: the denied state renders (the swap is kept)', async () => {
    const patch = held();
    const calls = stubFetch([patch.reply, empty(204), json(200, ARCHIVED), problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    await pressSaveThenDelete(u);
    await waitFor(() => expect(screen.getByText(ARCHIVED_BANNER)).toBeInTheDocument());
    patch.release(json(200, { ...MAIN, name: 'Main warehousex' }));
    expect(await screen.findByRole('heading', { level: 1, name: "You don't have access to warehouses." })).toBeInTheDocument();
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'DELETE', 'GET', 'GET']);
  });
});

// The same question for the Locations section's row actions. Edit, Add and Delete location are dialogs that cannot be
// closed while their request is out, so the person cannot press Delete warehouse meanwhile. Restore location is a row
// button: it can still be answering when the person's own Delete answers 204.
describe("/warehouses/[id] — a Restore location sent before the person's own Delete, answering after the 204", () => {
  const AFTER = "MAIN was deleted. It's now in the archived warehouses.";
  const B1_LIVE = { ...B1, archived_at: null };
  const tick = () =>
    act(async () => {
      await new Promise((t) => setTimeout(t, 50));
    });
  const restoreThenDelete = async (u: ReturnType<typeof userEvent.setup>) => {
    const locations = { initial: { kind: 'ok' as const, locations: [A1, Q1] }, canEdit: false, canRestore: true, canCreate: false, canDelete: false };
    renderWired(<WarehousePage initial={MAIN} canEdit={false} canRestore canDelete locations={locations} />);
    const spoken = textsOf(region());
    await u.click(screen.getByRole('button', { name: 'Archived' }));
    await u.click(await screen.findByRole('button', { name: 'Restore location B1' }));
    await u.click(screen.getByRole('button', { name: 'Delete warehouse' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete warehouse' }));
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    return spoken;
  };

  it('restored: "B1 was restored." is never spoken over the delete; the h1 keeps focus; the view is still re-read', async () => {
    const post = held();
    const calls = stubFetch([json(200, coll([B1])), post.reply, empty(204), json(200, ARCHIVED), json(200, coll([]))]);
    const u = userEvent.setup();
    const spoken = await restoreThenDelete(u);
    post.release(json(200, B1_LIVE));
    await tick();
    await tick();
    spoken.stop();
    expect(calls.map((c) => c.method)).toEqual(['GET', 'POST', 'DELETE', 'GET', 'GET']);
    expect(spoken.texts).not.toContain('B1 was restored.');
    expect(region().textContent).toBe(AFTER);
    expect(screen.getByRole('heading', { level: 1 })).toHaveFocus();
  });

  it('refused (its warehouse is archived now): no "Not restored" banner over the delete; the h1 keeps focus', async () => {
    const post = held();
    const calls = stubFetch([json(200, coll([B1])), post.reply, empty(204), json(200, ARCHIVED), json(200, coll([B1]))]);
    const u = userEvent.setup();
    const spoken = await restoreThenDelete(u);
    post.release(refused('storage_location.warehouse_is_archived', 'Fixture: its warehouse is archived.'));
    await tick();
    await tick();
    spoken.stop();
    // The view is re-read once, so the rows are the server's; the refusal's own "was it restored already?" read is not
    // needed, as nothing is said either way.
    expect(calls.map((c) => c.method)).toEqual(['GET', 'POST', 'DELETE', 'GET', 'GET']);
    expect(screen.queryByText('Not restored')).toBeNull();
    expect(screen.queryByText('Fixture: its warehouse is archived.')).toBeNull();
    expect(region().textContent).toBe(AFTER);
    expect(screen.getByRole('heading', { level: 1 })).toHaveFocus();
  });

  // RED M-1: the check is made again after every read Restore location awaits, not only when it answers.
  it('refused before the 204, its "restored already?" read answering after it: no notice, the h1 keeps focus', async () => {
    const check = held();
    const calls = stubFetch([json(200, coll([B1])), refused('transaction.contention', 'Fixture: try again.'), check.reply, empty(204), json(200, ARCHIVED), json(200, coll([B1]))]);
    const u = userEvent.setup();
    const locations = { initial: { kind: 'ok' as const, locations: [A1, Q1] }, canEdit: false, canRestore: true, canCreate: false, canDelete: false };
    renderWired(<WarehousePage initial={MAIN} canEdit={false} canRestore canDelete locations={locations} />);
    await u.click(screen.getByRole('button', { name: 'Archived' }));
    await u.click(await screen.findByRole('button', { name: 'Restore location B1' }));
    await waitFor(() => expect(calls).toHaveLength(3));
    await u.click(screen.getByRole('button', { name: 'Delete warehouse' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete warehouse' }));
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    check.release(json(200, coll([B1])));
    await tick();
    await tick();
    expect(screen.queryByText('Not restored')).toBeNull();
    expect(screen.queryByText('Fixture: try again.')).toBeNull();
    expect(region().textContent).toBe(AFTER);
    expect(screen.getByRole('heading', { level: 1 })).toHaveFocus();
  });

  // The refused branch's own re-check: its "restored already?" read finds the row gone, which would speak the server's
  // sentence and focus the section heading. Only this case reaches that check before the trailing one.
  it('refused before the 204, its "restored already?" read finding the row gone after it: nothing spoken, the h1 keeps focus', async () => {
    const check = held();
    const calls = stubFetch([json(200, coll([B1])), refused('transaction.contention', 'Fixture: try again.'), check.reply, empty(204), json(200, ARCHIVED), json(200, coll([]))]);
    const u = userEvent.setup();
    const locations = { initial: { kind: 'ok' as const, locations: [A1, Q1] }, canEdit: false, canRestore: true, canCreate: false, canDelete: false };
    renderWired(<WarehousePage initial={MAIN} canEdit={false} canRestore canDelete locations={locations} />);
    const spoken = textsOf(region());
    await u.click(screen.getByRole('button', { name: 'Archived' }));
    await u.click(await screen.findByRole('button', { name: 'Restore location B1' }));
    await waitFor(() => expect(calls).toHaveLength(3));
    await u.click(screen.getByRole('button', { name: 'Delete warehouse' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete warehouse' }));
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    check.release(json(200, coll([])));
    await tick();
    await tick();
    spoken.stop();
    expect(calls.map((c) => c.method)).toEqual(['GET', 'POST', 'GET', 'DELETE', 'GET', 'GET']);
    expect(spoken.texts).not.toContain('Fixture: try again.');
    expect(region().textContent).toBe(AFTER);
    expect(screen.getByRole('heading', { level: 1 })).toHaveFocus();
  });

  it('failed before the 204, its view re-read answering after it: no notice, the h1 keeps focus', async () => {
    const view = held();
    const calls = stubFetch([json(200, coll([B1])), problem(503, 'x'), view.reply, empty(204), json(200, ARCHIVED)]);
    const u = userEvent.setup();
    const locations = { initial: { kind: 'ok' as const, locations: [A1, Q1] }, canEdit: false, canRestore: true, canCreate: false, canDelete: false };
    renderWired(<WarehousePage initial={MAIN} canEdit={false} canRestore canDelete locations={locations} />);
    await u.click(screen.getByRole('button', { name: 'Archived' }));
    await u.click(await screen.findByRole('button', { name: 'Restore location B1' }));
    await waitFor(() => expect(calls).toHaveLength(3));
    await u.click(screen.getByRole('button', { name: 'Delete warehouse' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete warehouse' }));
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    view.release(json(200, coll([B1])));
    await tick();
    await tick();
    expect(screen.queryByText('Not restored')).toBeNull();
    expect(screen.queryByText(RESTORE_LOCATION.interrupted('B1'))).toBeNull();
    expect(region().textContent).toBe(AFTER);
    expect(screen.getByRole('heading', { level: 1 })).toHaveFocus();
  });

  it('control — own delete, then Restore warehouse, then a Restore location sent after it: "B1 was restored." IS spoken', async () => {
    const calls = stubFetch([empty(204), json(200, ARCHIVED), json(200, MAIN), json(200, MAIN), json(200, coll([B1])), json(200, B1_LIVE), json(200, coll([]))]);
    const u = userEvent.setup();
    const locations = { initial: { kind: 'ok' as const, locations: [A1, Q1] }, canEdit: false, canRestore: true, canCreate: false, canDelete: false };
    renderWired(<WarehousePage initial={MAIN} canEdit={false} canRestore canDelete locations={locations} />);
    await u.click(screen.getByRole('button', { name: 'Delete warehouse' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete warehouse' }));
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    await u.click(await screen.findByRole('button', { name: 'Restore warehouse' }));
    await waitFor(() => expect(region()).toHaveTextContent('MAIN was restored.'));
    await u.click(screen.getByRole('button', { name: 'Archived' }));
    await u.click(await screen.findByRole('button', { name: 'Restore location B1' }));
    await waitFor(() => expect(region().textContent).toBe('B1 was restored.'));
    expect(calls.map((c) => c.method)).toEqual(['DELETE', 'GET', 'POST', 'GET', 'GET', 'POST', 'GET']);
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

// FE-R8, location half (fe-r8-s3-ux.md "Revision 1 — FINAL" ruling (a); s3-fer8-final-red.md F-3, L-8): the 204 still
// speaks `after` at once (M10 above, unmoved). When the view re-read resolves, the page's region is replaced only if it
// has not been written since, by anyone, and only when the re-read shows the row is live (`afterRestored`) or the re-read
// failed (`afterListStale`). Which view was read decides with the presence of the id (H-2): the live view holding it, or
// the Archived view not holding it (the mirror arm), means restored in between.
describe('/warehouses/[id] — Delete location, the corrected "after" (FE-R8 location half)', () => {
  const AFTER = "A1 was deleted. It's now in this warehouse's archived locations.";
  const RESTORED = 'A1 was deleted, and has since been restored.';
  const STALE = "A1 was deleted. It's now in this warehouse's archived locations. We couldn't load the list again, so it may be out of date.";
  const A1_ARCHIVED = { ...A1, archived_at: '2026-11-12T00:00:00Z' };
  const settle = () =>
    act(async () => {
      await new Promise((t) => setTimeout(t, 50));
    });
  const deleteA1 = async (u: ReturnType<typeof userEvent.setup>) => {
    await u.click(screen.getByRole('button', { name: 'Delete A1' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete location' }));
  };

  // Mutation M1 (never replace) → red.
  it('restored in between: the live view\'s re-read holds the row → the region becomes "…and has since been restored."', async () => {
    const reread = held();
    stubFetch([empty(204), reread.reply]);
    const u = userEvent.setup();
    showSection();
    await deleteA1(u);
    await waitFor(() => expect(region().textContent).toBe(AFTER));
    reread.release(json(200, coll([A1, Q1])));
    await waitFor(() => expect(region().textContent).toBe(RESTORED));
  });

  // L-8. Mutation M4 (`l.code` in the live arm) → red: the sentence names the row on screen now.
  it('restored and renamed in between: "afterRestored" names the re-read row\'s code', async () => {
    stubFetch([empty(204), json(200, coll([{ ...A1, code: 'A1-NEW' }, Q1]))]);
    const u = userEvent.setup();
    showSection();
    await deleteA1(u);
    await waitFor(() => expect(region().textContent).toBe('A1-NEW was deleted, and has since been restored.'));
  });

  // RED H-2. Mutation M2 (decide on presence alone) → red.
  it('a view switch commits behind the dialog, so the re-read reads the Archived view holding the row → "after" stands', async () => {
    const toArchived = held();
    const calls = stubFetch([toArchived.reply, empty(204), json(200, coll([A1_ARCHIVED, B1]))]);
    const u = userEvent.setup();
    showSection();
    await u.click(within(section()).getByRole('button', { name: 'Archived' }));
    await u.click(screen.getByRole('button', { name: 'Delete A1' }));
    toArchived.release(json(200, coll([B1])));
    // Behind the modal (aria-hidden), so `hidden: true`.
    await waitFor(() => expect(screen.getByRole('heading', { level: 2, name: 'Archived locations', hidden: true })).toBeInTheDocument());
    await u.click(within(dialog()).getByRole('button', { name: 'Delete location' }));
    await waitFor(() => expect(calls).toHaveLength(3));
    expect(calls[2]!.url).toContain('archived=true');
    await waitFor(() => expect(within(section()).getByText('A1')).toBeInTheDocument());
    await settle();
    expect(region().textContent).toBe(AFTER);
  });

  // RED H-2's mirror arm (L-8, L-9). Mutation M3 (drop the mirror arm) → red.
  it('the same interleaving, but the Archived view does not hold the row (restored in between) → "afterRestored", by the code deleted', async () => {
    const toArchived = held();
    const reread = held();
    const calls = stubFetch([toArchived.reply, empty(204), reread.reply]);
    const u = userEvent.setup();
    showSection();
    await u.click(within(section()).getByRole('button', { name: 'Archived' }));
    await u.click(screen.getByRole('button', { name: 'Delete A1' }));
    toArchived.release(json(200, coll([B1])));
    // Behind the modal (aria-hidden), so `hidden: true`.
    await waitFor(() => expect(screen.getByRole('heading', { level: 2, name: 'Archived locations', hidden: true })).toBeInTheDocument());
    await u.click(within(dialog()).getByRole('button', { name: 'Delete location' }));
    await waitFor(() => expect(calls).toHaveLength(3));
    expect(calls[2]!.url).toContain('archived=true');
    expect(region().textContent).toBe(AFTER);
    reread.release(json(200, coll([B1])));
    await waitFor(() => expect(region().textContent).toBe(RESTORED));
  });

  it('the live view\'s re-read without the row → "after" stands', async () => {
    const reread = held();
    stubFetch([empty(204), reread.reply]);
    const u = userEvent.setup();
    showSection();
    await deleteA1(u);
    reread.release(json(200, coll([Q1])));
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Delete A1' })).toBeNull());
    await settle();
    expect(region().textContent).toBe(AFTER);
  });

  // Mutation M8 (only some failures count) → red on the case it drops.
  for (const [what, reply] of [
    ['server-error (503)', () => json(503, {})],
    ['not-found (404)', () => problem(404, 'not-found')],
  ] as const) {
    it(`a re-read that fails with ${what} → "afterListStale"`, async () => {
      stubFetch([empty(204), reply()]);
      const u = userEvent.setup();
      showSection();
      await deleteA1(u);
      await waitFor(() => expect(region().textContent).toBe(STALE));
    });
  }

  it('a re-read still out at the 30 s deadline → "afterListStale" (not before)', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    try {
      stubFetch([empty(204), 'hang']);
      const u = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
      showSection();
      await deleteA1(u);
      await waitFor(() => expect(region().textContent).toBe(AFTER));
      await vi.advanceTimersByTimeAsync(29_000);
      expect(region().textContent).toBe(AFTER);
      await vi.advanceTimersByTimeAsync(2_000);
      await waitFor(() => expect(region().textContent).toBe(STALE));
    } finally {
      vi.useRealTimers();
    }
  });

  // L-8. Mutation M7 (forbidden → stale) → red.
  it('a re-read answered 403 (the list is replaced by its denied state) → "after" stands', async () => {
    stubFetch([empty(204), problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    showSection();
    await deleteA1(u);
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Delete Q1' })).toBeNull());
    await settle();
    expect(region().textContent).toBe(AFTER);
  });

  // Superseded (FINAL list). Mutation M1′ (always replace, no generation check) → red.
  it('a second delete speaks its own "after" before the first re-read resolves → the second sentence stays', async () => {
    const first = held();
    const calls = stubFetch([empty(204), first.reply, empty(204), json(200, coll([A1]))]);
    const u = userEvent.setup();
    showSection();
    await deleteA1(u);
    await waitFor(() => expect(region().textContent).toBe(AFTER));
    await u.click(screen.getByRole('button', { name: 'Delete Q1' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete location' }));
    await waitFor(() => expect(region().textContent).toBe("Q1 was deleted. It's now in this warehouse's archived locations."));
    first.release(json(200, coll([A1, Q1])));
    await waitFor(() => expect(calls).toHaveLength(4));
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Delete Q1' })).toBeNull());
    await settle();
    expect(region().textContent).toBe("Q1 was deleted. It's now in this warehouse's archived locations.");
  });

  // s3-fer8-final-red.md F-3 "Superseded by the page". Mutation M5 (a section-local counter) → red.
  it('a warehouse Save speaks "… was saved." in the window → the restored re-read leaves the Save sentence', async () => {
    const reread = held();
    const calls = stubFetch([empty(204), reread.reply, json(200, { ...MAIN, name: 'Main 2' }), json(200, { ...MAIN, name: 'Main 2' })]);
    const u = userEvent.setup();
    const locations = { initial: { kind: 'ok' as const, locations: [A1, Q1] }, canEdit: false, canRestore: true, canCreate: false, canDelete: true };
    renderWired(<WarehousePage initial={MAIN} canEdit canRestore={false} locations={locations} />);
    await deleteA1(u);
    await waitFor(() => expect(region().textContent).toBe(AFTER));
    const name = screen.getByRole('textbox', { name: 'Name' });
    await u.clear(name);
    await u.type(name, 'Main 2');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(region().textContent).toBe('MAIN was saved.'));
    expect(calls.map((c) => c.method)).toEqual(['DELETE', 'GET', 'PATCH', 'GET']);
    reread.release(json(200, coll([A1, Q1])));
    await settle();
    await settle();
    expect(region().textContent).toBe('MAIN was saved.');
  });

  // fe-r6-saving-status-final-red.md A-2: a Save press empties the region through the page's COUNTED writer, so a pending
  // Locations correction is superseded by the press itself, before the Save answers. Mutation A-M6 (raw `writeRegion('')`
  // in WarehousePage) → red only here.
  it('a warehouse Save pressed while the location re-read is held supersedes its correction at the press (FE-R6 commit A)', async () => {
    const reread = held();
    const patch = held();
    const getAfter = held();
    const calls = stubFetch([empty(204), reread.reply, patch.reply, getAfter.reply]);
    const u = userEvent.setup();
    const locations = { initial: { kind: 'ok' as const, locations: [A1, Q1] }, canEdit: false, canRestore: true, canCreate: false, canDelete: true };
    renderWired(<WarehousePage initial={MAIN} canEdit canRestore={false} locations={locations} />);
    await deleteA1(u);
    await waitFor(() => expect(region().textContent).toBe(AFTER));
    const rec = textsOf(region());
    const name = screen.getByRole('textbox', { name: 'Name' });
    await u.clear(name);
    await u.type(name, 'Main 2');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(calls.map((c) => c.method)).toEqual(['DELETE', 'GET', 'PATCH']));
    reread.release(json(200, coll([A1, Q1])));
    await settle();
    await settle();
    expect(rec.texts).not.toContain(RESTORED);
    patch.release(json(200, { ...MAIN, name: 'Main 2' }));
    await waitFor(() => expect(calls.map((c) => c.method)).toEqual(['DELETE', 'GET', 'PATCH', 'GET']));
    getAfter.release(json(200, { ...MAIN, name: 'Main 2' }));
    await waitFor(() => expect(region().textContent).toBe('MAIN was saved.'));
    expect(rec.texts).not.toContain(RESTORED);
    expect(rec.texts).toEqual([AFTER, '', 'MAIN was saved.']);
    rec.stop();
  });

  // Every page writer goes through the page's wrapper (F-3): Delete warehouse too. Mutation M5′ (Delete warehouse writes
  // the region directly) → red.
  it('a Delete warehouse in the window speaks its own "after" → the location re-read leaves it', async () => {
    const reread = held();
    stubFetch([empty(204), reread.reply, empty(204), json(200, ARCHIVED)]);
    const u = userEvent.setup();
    const locations = { initial: { kind: 'ok' as const, locations: [A1, Q1] }, canEdit: false, canRestore: true, canCreate: false, canDelete: true };
    renderWired(<WarehousePage initial={MAIN} canEdit={false} canRestore canDelete locations={locations} />);
    await deleteA1(u);
    await waitFor(() => expect(region().textContent).toBe(AFTER));
    await u.click(screen.getByRole('button', { name: 'Delete warehouse' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete warehouse' }));
    await waitFor(() => expect(region().textContent).toBe("MAIN was deleted. It's now in the archived warehouses."));
    reread.release(json(200, coll([A1, Q1])));
    await settle();
    await settle();
    expect(region().textContent).toBe("MAIN was deleted. It's now in the archived warehouses.");
  });

  // fe-r8-location-red.md L-5: `afterListStale` is `after` then `WAITING.listStale` (copy/* may not import copy/*, so the
  // text is written out there; this ties the three together). Mutation ML-5 (one word of `WAITING.listStale`) → red.
  it('"afterListStale" is "after" followed by the shared list-stale sentence', () => {
    for (const c of ['A1', 'X-9']) expect(DELETE_LOCATION.afterListStale(c)).toBe(`${DELETE_LOCATION.after(c)} ${WAITING.listStale}`);
  });

  // fe-r8-location-red.md L-1: the decision reads the delete's OWN re-read answer, never a view switch queued behind it.
  // Mutation ML-1 (an `await` between the re-read and the decision) → red: the switch's answer would win.
  it('a view switch queued behind the delete\'s re-read does not decide its sentence', async () => {
    const reread = held();
    const calls = stubFetch([empty(204), reread.reply, json(200, coll([B1]))]);
    const u = userEvent.setup();
    showSection();
    await deleteA1(u);
    await waitFor(() => expect(region().textContent).toBe(AFTER));
    await u.click(within(section()).getByRole('button', { name: 'Archived' }));
    reread.release(json(200, coll([Q1])));
    await waitFor(() => expect(calls).toHaveLength(3));
    expect(calls[2]!.url).toContain('archived=true');
    await waitFor(() => expect(screen.getByRole('heading', { level: 2, name: 'Archived locations' })).toBeInTheDocument());
    await settle();
    expect(region().textContent).toBe(AFTER);
  });

  // fe-r8-location-red.md L-2: Restore warehouse can speak in the window. Re-routed by fe-r9-deviations-red.md L-B so the
  // Restore press is the only region write in the window (since FE-R9 a Delete warehouse press empties the region through
  // the counted writer, which would drop the correction whatever writer Restore holds): a page Restore whose re-read fails
  // (live from the 200 body, region error) → location delete A1 (204, re-read held) → Retry reads the warehouse archived
  // elsewhere (no region write) → Restore warehouse → the held re-read lands with A1 restored in between, so a correction
  // is pending. Mutation ML-2 (RestoreActions given the raw region writer) → red. The arrival message cannot speak in the
  // window: it is written by a 0 ms timer at mount, before any delete.
  it('Restore warehouse in the window speaks its own sentence → the location re-read leaves it', async () => {
    const reread = held();
    const calls = stubFetch([json(200, MAIN), json(500, {}), empty(204), reread.reply, json(200, ARCHIVED), json(200, MAIN), json(200, MAIN)]);
    const u = userEvent.setup();
    const locations = { initial: { kind: 'ok' as const, locations: [A1, Q1] }, canEdit: false, canRestore: true, canCreate: false, canDelete: true };
    renderWired(<WarehousePage initial={ARCHIVED} canEdit={false} canRestore canDelete locations={locations} />);
    await u.click(screen.getByRole('button', { name: 'Restore warehouse' }));
    await waitFor(() => expect(region().textContent).toBe('MAIN was restored.'));
    await deleteA1(u);
    await waitFor(() => expect(region().textContent).toBe(AFTER));
    await u.click(await screen.findByRole('button', { name: 'Retry' }));
    await u.click(await screen.findByRole('button', { name: 'Restore warehouse' }));
    await waitFor(() => expect(region().textContent).toBe('MAIN was restored.'));
    expect(calls.map((c) => c.method)).toEqual(['POST', 'GET', 'DELETE', 'GET', 'GET', 'POST', 'GET']);
    reread.release(json(200, coll([A1, Q1])));
    await settle();
    await settle();
    expect(region().textContent).toBe('MAIN was restored.');
  });

  // fe-r8-location-red.md L-3: the view that decides is the one the re-read read, not the one on screen at the 204.
  // Mutation ML-3 (decide with the view at the 204) → red.
  it('a view switch still in flight at the 204: the re-read reads the switched view, and "after" stands', async () => {
    const toArchived = held();
    const calls = stubFetch([toArchived.reply, empty(204), json(200, coll([A1_ARCHIVED, B1]))]);
    const u = userEvent.setup();
    showSection();
    await u.click(within(section()).getByRole('button', { name: 'Archived' }));
    await deleteA1(u);
    await waitFor(() => expect(region().textContent).toBe(AFTER));
    toArchived.release(json(200, coll([B1])));
    await waitFor(() => expect(calls).toHaveLength(3));
    expect(calls[2]!.url).toContain('archived=true');
    await waitFor(() => expect(within(section()).getByText('A1')).toBeInTheDocument());
    await settle();
    expect(region().textContent).toBe(AFTER);
  });

  // fe-r8-location-red.md L-4: a close that was not a 204 never gets a correction, even when its re-read fails.
  // Mutation ML-4 (speak the correction when nothing was said) → red.
  it('a refusal\'s Close with a failed re-read speaks nothing', async () => {
    const calls = stubFetch([refused('storage_location.holds_stock', L8), json(503, {})]);
    const u = userEvent.setup();
    showSection();
    await deleteA1(u);
    await waitFor(() => expect(within(dialog()).getByText('Not deleted')).toHaveFocus());
    await u.click(within(dialog()).getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(calls).toHaveLength(2));
    await settle();
    expect(region().textContent).toBe('');
    expect(region().textContent).not.toContain('was deleted');
    // locations-stale-banner T-3b: the failed re-read is shown (the generic body), never spoken (FE-R8 R-2).
    const retry = within(section()).getByRole('button', { name: 'Retry' });
    expect(bannerTexts(retry)).toEqual([WENT_WRONG, LIST_STATES.refetchFailed]);
  });

  // s3-fer8-final-red.md F-3 "Same text". Mutation M6 (compare the region's text, not a generation) → red.
  it('the same row deleted twice in the window (restored, then deleted again): the first re-read reading it live does not say "restored"', async () => {
    const first = held();
    const calls = stubFetch([empty(204), first.reply, empty(204), json(200, coll([Q1]))]);
    const u = userEvent.setup();
    showSection();
    await deleteA1(u);
    await waitFor(() => expect(region().textContent).toBe(AFTER));
    // The row stays on screen until the re-read commits; someone restored A1 meanwhile, so the second delete is a 204.
    await deleteA1(u);
    await waitFor(() => expect(calls).toHaveLength(3));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    first.release(json(200, coll([A1, Q1])));
    await waitFor(() => expect(calls).toHaveLength(4));
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Delete A1' })).toBeNull());
    await settle();
    expect(region().textContent).toBe(AFTER);
  });
});

/** A banner's heading and body, from its Retry button (RefetchFailedBanner: heading `p`, body `p`, the action beside them). */
function bannerTexts(retry: HTMLElement): (string | null)[] {
  let el: HTMLElement | null = retry.parentElement;
  while (el !== null && el.querySelectorAll('p').length < 2) el = el.parentElement;
  return [...el!.querySelectorAll('p')].map((p) => p.textContent);
}

// locations-stale-banner-design.md "Revision 1 — FINAL" (F-1…F-9) as corrected by locations-stale-banner-final-red.md and
// locations-stale-banner-po-arch.md (H-1 option (a′)): a write's view re-read that fails shows a warning banner in the
// section, whose body is that write's stale sentence; its Retry re-reads the view.
describe('/warehouses/[id] — Locations, the stale banner after a failed re-read', () => {
  const STALE = DELETE_LOCATION.afterListStale('A1');
  const STALE_Q1 = DELETE_LOCATION.afterListStale('Q1');
  const B2 = loc('55555555-5555-4555-8555-555555555555', 'B2', 'bin', '2026-04-02T09:00:00Z');
  const retries = () => within(section()).queryAllByRole('button', { name: 'Retry' });
  const headingEl = () => document.getElementById('locations-heading');
  const offline = () => {
    throw new TypeError('Failed to fetch');
  };
  const settle = () =>
    act(async () => {
      await new Promise((t) => setTimeout(t, 50));
    });
  const deleteRow = async (u: ReturnType<typeof userEvent.setup>, code: string) => {
    await u.click(screen.getByRole('button', { name: `Delete ${code}` }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete location' }));
  };

  // T-1. Mutations M-1 (never set the banner), M-4 (heading spoken on appearance), M-12 (no body attach) → red.
  it('T-1 a 204 whose re-read answers 503: one banner, "Something went wrong on our side." over the stale sentence, with Retry; the region says the same; focus on the heading; A1 still listed', async () => {
    stubFetch([empty(204), json(503, {})]);
    const u = userEvent.setup();
    showSection();
    await deleteRow(u, 'A1');
    await waitFor(() => expect(retries()).toHaveLength(1));
    expect(bannerTexts(retries()[0]!)).toEqual([WENT_WRONG, STALE]);
    await settle();
    expect(region().textContent).toBe(STALE);
    expect(headingEl()).toHaveFocus();
    expect(within(section()).getByText('A1')).toBeInTheDocument();
  });

  // T-2. Mutation M-9 (not-found treated as forbidden) → red.
  for (const [what, reply, heading] of [
    ['unreachable', offline, COULD_NOT_REACH],
    ['not found (404)', () => problem(404, 'not-found'), WENT_WRONG],
  ] as const) {
    it(`T-2 a re-read that fails ${what}: heading "${heading}", the same body`, async () => {
      stubFetch([empty(204), reply]);
      const u = userEvent.setup();
      showSection();
      await deleteRow(u, 'A1');
      await waitFor(() => expect(retries()).toHaveLength(1));
      expect(bannerTexts(retries()[0]!)).toEqual([heading, STALE]);
      expect(region().textContent).toBe(STALE);
    });
  }

  // T-3c (final RED L-4: the queued variant). Mutations M-13 (a failed read over an up banner resets the body) and M-16
  // (an `await` between the delete's re-read and its body attach) → red.
  it("T-3c a refusal's Close re-read queued behind the delete's: both fail, and the body is still the delete's stale sentence", async () => {
    const r1 = held();
    const r2 = held();
    const calls = stubFetch([empty(204), r1.reply, refused('storage_location.holds_stock', L8), r2.reply]);
    const u = userEvent.setup();
    showSection();
    await deleteRow(u, 'A1');
    await waitFor(() => expect(calls).toHaveLength(2));
    await deleteRow(u, 'Q1');
    await waitFor(() => expect(within(dialog()).getByText('Not deleted')).toHaveFocus());
    await u.click(within(dialog()).getByRole('button', { name: 'Close' }));
    await settle();
    expect(calls).toHaveLength(3);
    r1.release(json(503, {}));
    await waitFor(() => expect(calls).toHaveLength(4));
    await waitFor(() => expect(retries()).toHaveLength(1));
    expect(bannerTexts(retries()[0]!)).toEqual([WENT_WRONG, STALE]);
    r2.release(json(503, {}));
    await settle();
    expect(retries()).toHaveLength(1);
    expect(bannerTexts(retries()[0]!)).toEqual([WENT_WRONG, STALE]);
  });

  // T-4. Mutation M-3 (no clear on ok) → red.
  it('T-4 a later delete whose re-read succeeds clears the banner', async () => {
    stubFetch([empty(204), json(503, {}), empty(204), json(200, coll([]))]);
    const u = userEvent.setup();
    showSection();
    await deleteRow(u, 'A1');
    await waitFor(() => expect(retries()).toHaveLength(1));
    await deleteRow(u, 'Q1');
    await waitFor(() => expect(within(section()).getByText('No locations in this warehouse yet.')).toBeInTheDocument());
    expect(retries()).toHaveLength(0);
  });

  // T-5. Mutation M-8 (always focus the heading on success) → red on the variant.
  it('T-5 Retry succeeds (live view): the banner goes, A1 goes, focus on the heading, the region says how many are shown', async () => {
    const calls = stubFetch([empty(204), json(503, {}), json(200, coll([Q1]))]);
    const u = userEvent.setup();
    showSection();
    await deleteRow(u, 'A1');
    await waitFor(() => expect(retries()).toHaveLength(1));
    await u.click(retries()[0]!);
    await waitFor(() => expect(retries()).toHaveLength(0));
    expect(calls).toHaveLength(3);
    expect(calls[2]!.url).not.toContain('archived=true');
    expect(within(section()).queryByText('A1')).toBeNull();
    expect(headingEl()).toHaveFocus();
    await waitFor(() => expect(region().textContent).toBe(LOCATIONS_SECTION.shown(1, '', false)));
  });

  it('T-5 Retry succeeds (Archived view, after a restore whose re-read failed): "2 locations shown. Archived."', async () => {
    stubFetch([json(200, coll([B1, B2])), json(200, { ...B1, archived_at: null }), json(503, {}), json(200, coll([B2, { ...B1, id: '66666666-6666-4666-8666-666666666666', code: 'B3' }]))]);
    const u = userEvent.setup();
    showSection();
    await u.click(within(section()).getByRole('button', { name: 'Archived' }));
    await u.click(await screen.findByRole('button', { name: 'Restore location B1' }));
    await waitFor(() => expect(retries()).toHaveLength(1));
    expect(bannerTexts(retries()[0]!)).toEqual([WENT_WRONG, RESTORE_LOCATION.afterListStale('B1')]);
    await u.click(retries()[0]!);
    await waitFor(() => expect(retries()).toHaveLength(0));
    await waitFor(() => expect(region().textContent).toBe('2 locations shown. Archived.'));
  });

  it('T-5 Retry succeeds with no rows left: the region speaks the empty heading', async () => {
    stubFetch([empty(204), json(503, {}), json(200, coll([]))]);
    const u = userEvent.setup();
    showSection();
    await deleteRow(u, 'A1');
    await waitFor(() => expect(retries()).toHaveLength(1));
    await u.click(retries()[0]!);
    await waitFor(() => expect(region().textContent).toBe(LOCATIONS_SECTION.noneLive));
  });

  it('T-5 variant: focus moved to another control before the Retry answers stays there', async () => {
    const answer = held();
    stubFetch([empty(204), json(503, {}), answer.reply]);
    const u = userEvent.setup();
    showSection();
    await deleteRow(u, 'A1');
    await waitFor(() => expect(retries()).toHaveLength(1));
    await u.click(retries()[0]!);
    const other = within(section()).getByRole('button', { name: 'Delete Q1' });
    act(() => other.focus());
    answer.release(json(200, coll([Q1])));
    await waitFor(() => expect(retries()).toHaveLength(0));
    await settle();
    expect(other).toHaveFocus();
  });

  // T-6. Mutations M-6 (no blank at press) and M-7 (the banner keyed by a counter) → red. The banner unmounting during a
  // Retry (a write's re-read clearing it first) is a declared review check (final RED L-9).
  it('T-6 Retry fails twice: blank while the second is out, then the heading; focus stays on the same Retry; the body keeps the outcome', async () => {
    const second = held();
    stubFetch([empty(204), json(503, {}), json(503, {}), second.reply]);
    const u = userEvent.setup();
    showSection();
    await deleteRow(u, 'A1');
    await waitFor(() => expect(retries()).toHaveLength(1));
    const retry = retries()[0]!;
    await u.click(retry);
    await waitFor(() => expect(region().textContent).toBe(WENT_WRONG));
    expect(retries()[0]).toBe(retry);
    expect(retry).toHaveFocus();
    await u.click(retry);
    await waitFor(() => expect(region().textContent).toBe(''));
    second.release(json(503, {}));
    await waitFor(() => expect(region().textContent).toBe(WENT_WRONG));
    expect(retries()).toHaveLength(1);
    expect(retries()[0]).toBe(retry);
    expect(retry).toHaveFocus();
    expect(bannerTexts(retry)).toEqual([WENT_WRONG, STALE]);
  });

  // T-7. Mutation M-5 (Retry writes with plain announce) → red.
  it("T-7 a delete that lands while Retry is out keeps its own sentence: the Retry's is dropped", async () => {
    const answer = held();
    const calls = stubFetch([empty(204), json(503, {}), answer.reply, empty(204), json(200, coll([]))]);
    const u = userEvent.setup();
    showSection();
    await deleteRow(u, 'A1');
    await waitFor(() => expect(retries()).toHaveLength(1));
    await u.click(retries()[0]!);
    await deleteRow(u, 'Q1');
    await waitFor(() => expect(region().textContent).toBe(DELETE_LOCATION.after('Q1')));
    answer.release(json(200, coll([Q1])));
    await waitFor(() => expect(calls).toHaveLength(5));
    await waitFor(() => expect(within(section()).getByText('No locations in this warehouse yet.')).toBeInTheDocument());
    await settle();
    expect(region().textContent).toBe(DELETE_LOCATION.after('Q1'));
  });

  // T-8 (+ final RED L-5's held-switch assertion).
  it('T-8 the banner, then a failed switch: no banner while the switch is out, then exactly one (the list\'s)', async () => {
    const toArchived = held();
    stubFetch([empty(204), json(503, {}), toArchived.reply]);
    const u = userEvent.setup();
    showSection();
    await deleteRow(u, 'A1');
    await waitFor(() => expect(retries()).toHaveLength(1));
    await u.click(within(section()).getByRole('button', { name: 'Archived' }));
    await waitFor(() => expect(retries()).toHaveLength(0));
    toArchived.release(json(503, {}));
    await waitFor(() => expect(retries()).toHaveLength(1));
    expect(bannerTexts(retries()[0]!)).toEqual([WENT_WRONG, LIST_STATES.refetchFailed]);
  });

  it('T-8 the banner, then a successful switch: no banner, there or back', async () => {
    stubFetch([empty(204), json(503, {}), json(200, coll([B1])), json(200, coll([Q1]))]);
    const u = userEvent.setup();
    showSection();
    await deleteRow(u, 'A1');
    await waitFor(() => expect(retries()).toHaveLength(1));
    await u.click(within(section()).getByRole('button', { name: 'Archived' }));
    await screen.findByRole('heading', { level: 2, name: 'Archived locations' });
    expect(retries()).toHaveLength(0);
    await u.click(within(section()).getByRole('button', { name: 'Back to locations' }));
    await screen.findByRole('heading', { level: 2, name: 'Locations' });
    await settle();
    expect(retries()).toHaveLength(0);
  });

  // T-8c (final RED M-3, ARCH (a′)). Mutation M-15 (`listBannerUp` latched once true) → red.
  it("T-8c a failed switch, then the same switch succeeds (the list's banner goes), then a delete whose re-read fails: the section's banner shows", async () => {
    stubFetch([json(503, {}), json(200, coll([B1])), json(200, coll([A1, Q1])), empty(204), json(503, {})]);
    const u = userEvent.setup();
    showSection();
    await u.click(within(section()).getByRole('button', { name: 'Archived' }));
    await waitFor(() => expect(retries()).toHaveLength(1));
    await u.click(within(section()).getByRole('button', { name: 'Archived' }));
    await screen.findByRole('heading', { level: 2, name: 'Archived locations' });
    expect(retries()).toHaveLength(0);
    await u.click(within(section()).getByRole('button', { name: 'Back to locations' }));
    await screen.findByRole('heading', { level: 2, name: 'Locations' });
    await deleteRow(u, 'A1');
    await waitFor(() => expect(retries()).toHaveLength(1));
    expect(bannerTexts(retries()[0]!)).toEqual([WENT_WRONG, STALE]);
  });

  // T-9. Mutation M-3 (no clear on ok) → red on the first case.
  it('T-9 queued re-reads, failed then ok: no banner', async () => {
    const r1 = held();
    const r2 = held();
    stubFetch([empty(204), r1.reply, empty(204), r2.reply]);
    const u = userEvent.setup();
    showSection();
    await deleteRow(u, 'A1');
    await deleteRow(u, 'Q1');
    await waitFor(() => expect(region().textContent).toBe(DELETE_LOCATION.after('Q1')));
    r1.release(json(503, {}));
    await waitFor(() => expect(retries()).toHaveLength(1));
    r2.release(json(200, coll([])));
    await waitFor(() => expect(within(section()).getByText('No locations in this warehouse yet.')).toBeInTheDocument());
    await settle();
    expect(retries()).toHaveLength(0);
  });

  it("T-9 queued re-reads, ok then failed: the banner, with the later delete's sentence", async () => {
    const r1 = held();
    const r2 = held();
    stubFetch([empty(204), r1.reply, empty(204), r2.reply]);
    const u = userEvent.setup();
    showSection();
    await deleteRow(u, 'A1');
    await deleteRow(u, 'Q1');
    await waitFor(() => expect(region().textContent).toBe(DELETE_LOCATION.after('Q1')));
    r1.release(json(200, coll([Q1])));
    await waitFor(() => expect(within(section()).queryByText('A1')).toBeNull());
    r2.release(json(503, {}));
    await waitFor(() => expect(retries()).toHaveLength(1));
    expect(bannerTexts(retries()[0]!)).toEqual([WENT_WRONG, STALE_Q1]);
    expect(region().textContent).toBe(STALE_Q1);
  });

  // Commit RED L-4 / L-5: a Retry queued behind a view switch that itself queued behind a write's re-read. The banner is
  // up over the live view; Delete Q1's re-read R1 is held; Archived is pressed (S queues behind R1); Retry is pressed (R2
  // queues behind S). S lands on the Archived view, so R2 re-reads that view.
  const queuedRetry = async (u: ReturnType<typeof userEvent.setup>, r1: ReturnType<typeof held>) => {
    await deleteRow(u, 'A1');
    await waitFor(() => expect(retries()).toHaveLength(1));
    await deleteRow(u, 'Q1');
    await waitFor(() => expect(region().textContent).toBe(DELETE_LOCATION.after('Q1')));
    await u.click(within(section()).getByRole('button', { name: 'Archived' }));
    await u.click(retries()[0]!);
    await waitFor(() => expect(region().textContent).toBe(''));
    r1.release(json(200, coll([A1])));
    await screen.findByRole('heading', { level: 2, name: 'Archived locations' });
  };

  // L-4 (a). Mutation M-20 (speak the failure heading whatever view is on screen) → red.
  it('a Retry queued behind a switch, whose re-read of the switched view fails: the Retry speaks nothing (the switch spoke)', async () => {
    const r1 = held();
    const calls = stubFetch([empty(204), json(503, {}), empty(204), r1.reply, json(200, coll([B1, B2])), json(503, {})]);
    const u = userEvent.setup();
    showSection();
    await queuedRetry(u, r1);
    await waitFor(() => expect(calls).toHaveLength(6));
    expect(calls[5]!.url).toContain('archived=true');
    await settle();
    expect(region().textContent).toBe('');
  });

  // L-5 (L-10's view check). Mutation M-21 (drop the `forView` check on ok) → red.
  it('a Retry queued behind a switch, whose re-read of the switched view succeeds: the Retry speaks nothing (the switch spoke)', async () => {
    const r1 = held();
    const calls = stubFetch([empty(204), json(503, {}), empty(204), r1.reply, json(200, coll([B1, B2])), json(200, coll([B1, B2]))]);
    const u = userEvent.setup();
    showSection();
    await queuedRetry(u, r1);
    await waitFor(() => expect(calls).toHaveLength(6));
    await settle();
    expect(region().textContent).toBe('');
  });

  // L-5 (L-9's focus recovery). Mutation P6 (the recovery removed) → red.
  it('the banner unmounts during a Retry (a write\'s re-read clears it first), then the Retry fails: focus goes to the heading, the banner shows the generic body', async () => {
    const r1 = held();
    const r2 = held();
    stubFetch([empty(204), json(503, {}), empty(204), r1.reply, r2.reply]);
    const u = userEvent.setup();
    showSection();
    await deleteRow(u, 'A1');
    await waitFor(() => expect(retries()).toHaveLength(1));
    await deleteRow(u, 'Q1');
    await waitFor(() => expect(region().textContent).toBe(DELETE_LOCATION.after('Q1')));
    await u.click(retries()[0]!);
    expect(retries()[0]).toHaveFocus();
    r1.release(json(200, coll([A1])));
    await waitFor(() => expect(retries()).toHaveLength(0));
    r2.release(json(503, {}));
    await waitFor(() => expect(retries()).toHaveLength(1));
    expect(bannerTexts(retries()[0]!)).toEqual([WENT_WRONG, LIST_STATES.refetchFailed]);
    expect(headingEl()).toHaveFocus();
  });

  // T-12 (final RED M-1). Mutation M-17 (no kind filter on the Retry failure path) → red.
  it('T-12 a Retry answered 403 shows the denied sentence; the region stays blank, never "Something went wrong"', async () => {
    stubFetch([empty(204), json(503, {}), problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    showSection();
    await deleteRow(u, 'A1');
    await waitFor(() => expect(retries()).toHaveLength(1));
    await u.click(retries()[0]!);
    await screen.findByText("You don't have access to this warehouse's locations.");
    await settle();
    expect(region().textContent).toBe('');
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


// delete-swap (delete-swap-final-red.md H-1 branch A1 + M-1; delete-swap-design.md Revision 1 (a) (f) (g) (h) (n) (r)): a 204 whose
// own re-read answers 403/404 swaps the page to ST-4b/ST-5. Measured on the shipped code before this test was written (step 0):
// focus moves ONCE, to the swapped h1 — `closeDelete`'s flushSync commits the pending swap together with the close (React's
// unified sync lanes), so the page h1 is already gone when it would be focused — nothing says "was deleted" (as on the person
// page; UX Q-UX-A open), and the router refreshes once (R-14b parity). Pinned here; the page was not changed for it.
describe('Delete warehouse — its own re-read hides the record (delete-swap, branch A1)', () => {
  for (const [st, answer, heading] of [
    [403, () => problem(403, 'permission-denied'), "You don't have access to warehouses."],
    [404, () => problem(404, 'not-found'), "We couldn't find that."],
  ] as const) {
    it(`a 204 whose re-read answers ${st}: focus moves once, to "${heading}"; nothing says it was deleted; one refresh; no dialog`, async () => {
      const calls = stubFetch([empty(204), answer()]);
      const u = userEvent.setup();
      showWarehouse();
      await u.click(screen.getByRole('button', { name: 'Delete warehouse' }));
      const stops: string[] = [];
      const rec = (e: FocusEvent) => {
        const t = e.target as HTMLElement;
        stops.push(`${t.tagName} ${t.textContent?.trim()}`);
      };
      document.addEventListener('focusin', rec);
      try {
        await u.click(within(dialog()).getByRole('button', { name: 'Delete warehouse' }));
        await screen.findByRole('heading', { level: 1, name: heading });
        await new Promise((t) => setTimeout(t, 50));
      } finally {
        document.removeEventListener('focusin', rec);
      }
      // (h) every focus stop after the pressed button's own: exactly the swapped h1 (M-1: all elements, not only headings).
      const pressed = stops.findIndex((s) => s.startsWith('BUTTON'));
      expect(stops[pressed]).toBe('BUTTON Delete warehouse');
      expect(stops.slice(pressed + 1)).toEqual([`H1 ${heading}`]);
      // (a)
      expect(screen.getByRole('heading', { level: 1, name: heading })).toHaveFocus();
      // (n)
      expect(document.body.textContent).not.toContain("MAIN was deleted. It's now in the archived warehouses.");
      expect(document.body.textContent).not.toContain('MAIN was deleted, and has since been restored.');
      // (r)
      expect(nav.refresh).toHaveBeenCalledTimes(1);
      // (f) / (g)
      expect(calls.map((c) => c.method)).toEqual(['DELETE', 'GET']);
      expect(screen.queryByRole('dialog')).toBeNull();
    });
  }
});
