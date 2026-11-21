import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ refresh: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ refresh: nav.refresh }) }));
import { WarehousePage } from '@/app/(app)/warehouses/[id]/WarehousePage';
import type { StorageLocation, Warehouse } from '@/lib/api/warehouses';
import { wireEnum } from '@/lib/bff/surface';
import { held, json, refused, renderWired, stubFetch } from './harness';

// The supplier catalogue's S-4 RED M-1, probed on its shipped twin: an Edit location dialog that is open when the warehouse
// page turns archived. Reached by the page's own Save: pressed first (its request in flight), then Edit A1 opened and a
// code typed; the Save answers and its re-read finds the warehouse archived elsewhere, so the page commits the archived
// warehouse while the dialog is open. The dialog must stay open with what was typed, its Save then meets the archived
// refusal and shows it under Not saved (S-4 RED-2 L-3: measured, not assumed), and it must not come back by itself after
// Restore warehouse.

afterEach(() => {
  vi.unstubAllGlobals();
  nav.refresh.mockClear();
});

const WID = '6a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';
const MAIN: Warehouse = { id: WID, code: 'MAIN', name: 'Main warehouse', country_code: 'GB', archived_at: null };
const ARCHIVED_MAIN: Warehouse = { ...MAIN, name: 'Main store', archived_at: '2026-03-04T10:00:00Z' };
const A1: StorageLocation = { id: '11111111-1111-4111-8111-111111111111', code: 'A1', kind: 'bin', archived_at: null };
const coll = (items: StorageLocation[]) => json(200, { items, next_cursor: null, previous_cursor: null, tenant_has_rows: true });
/** `StorageLocationWriter::WAREHOUSE_ARCHIVED_ON_UPDATE`. */
const ARCHIVED_ON_UPDATE = "This warehouse is archived, so its locations can't be changed until it's restored.";
/** `StorageLocationWriter::WAREHOUSE_ARCHIVED_ON_CREATE` / `::WAREHOUSE_ARCHIVED_ON_DELETE`. */
const ARCHIVED_ON_CREATE = "This warehouse is archived, so no location can be added to it until it's restored.";
const ARCHIVED_ON_DELETE = "This location's warehouse is archived, so the location can't be deleted until the warehouse is restored.";
const KINDS = wireEnum('StorageLocation', 'kind');
const RESTORED = { ...MAIN, name: 'Main store' };

/** The warehouse's own Save, pressed first: its request is held until the test releases it. */
async function saveWarehouse(u: ReturnType<typeof userEvent.setup>) {
  const name = screen.getByRole('textbox', { name: 'Name' });
  await u.clear(name);
  await u.type(name, 'Main store');
  await u.click(screen.getByRole('button', { name: 'Save' }));
}

describe('/warehouses/[id] — an Edit location dialog open when the page turns archived', () => {
  it('stays open with what was typed and focus inside it; its Save shows the archived refusal under Not saved; after Cancel and Restore warehouse it does not come back by itself', async () => {
    const s = held();
    const calls = stubFetch([s.reply, json(200, ARCHIVED_MAIN), refused('warehouse.is_archived', ARCHIVED_ON_UPDATE), coll([A1]), json(200, { ...MAIN, name: 'Main store' }), json(200, { ...MAIN, name: 'Main store' })]);
    const u = userEvent.setup();
    renderWired(<WarehousePage initial={MAIN} canEdit canRestore locations={{ initial: { kind: 'ok', locations: [A1] }, canEdit: true, canRestore: true, canCreate: false }} />);
    const name = screen.getByRole('textbox', { name: 'Name' });
    await u.clear(name);
    await u.type(name, 'Main store');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await u.click(screen.getByRole('button', { name: 'Edit A1' }));
    const code = within(screen.getByRole('dialog')).getByRole('textbox', { name: 'Code' });
    await u.clear(code);
    await u.type(code, 'A1X');
    await act(async () => s.release(json(200, { ...MAIN, name: 'Main store' })));
    await waitFor(() => expect(screen.getByText(/This warehouse was archived on/)).toBeInTheDocument());
    expect(screen.queryByRole('dialog')).not.toBeNull();
    expect(within(screen.getByRole('dialog')).getByRole('textbox', { name: 'Code' })).toHaveValue('A1X');
    expect(screen.getByRole('dialog').contains(document.activeElement)).toBe(true);
    await u.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(within(screen.getByRole('dialog')).getByText(ARCHIVED_ON_UPDATE)).toBeInTheDocument());
    expect(within(screen.getByRole('dialog')).getByText('Not saved')).toBeInTheDocument();
    await u.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Cancel' }));
    expect(screen.queryByRole('dialog')).toBeNull();
    await waitFor(() => expect(calls).toHaveLength(4));
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET', 'PATCH', 'GET']);
    await u.click(await screen.findByRole('button', { name: 'Restore warehouse' }));
    await waitFor(() => expect(screen.queryByText(/This warehouse was archived on/)).toBeNull());
    expect(screen.queryByRole('dialog')).toBeNull();
  });

  // S-4 RED-2 M-1 (shipped): the Add location dialog had the same shape (`adding && mayAdd`).
  it('Add location: stays open with what was typed and focus inside it; its Add shows the archived refusal under Not added; after Cancel and Restore warehouse it does not come back by itself', async () => {
    const s = held();
    const calls = stubFetch([s.reply, json(200, ARCHIVED_MAIN), refused('warehouse.is_archived', ARCHIVED_ON_CREATE), coll([A1]), json(200, RESTORED), json(200, RESTORED)]);
    const u = userEvent.setup();
    renderWired(<WarehousePage initial={MAIN} canEdit canRestore locations={{ initial: { kind: 'ok', locations: [A1] }, canEdit: true, canRestore: true, canCreate: true }} />);
    await saveWarehouse(u);
    await u.click(screen.getAllByRole('button', { name: 'Add location' })[0]!);
    await u.type(within(screen.getByRole('dialog')).getByRole('textbox', { name: 'Code' }), 'B9');
    await act(async () => s.release(json(200, RESTORED)));
    await waitFor(() => expect(screen.getByText(/This warehouse was archived on/)).toBeInTheDocument());
    expect(screen.queryByRole('dialog')).not.toBeNull();
    expect(within(screen.getByRole('dialog')).getByRole('textbox', { name: 'Code' })).toHaveValue('B9');
    expect(screen.getByRole('dialog').contains(document.activeElement)).toBe(true);
    await u.selectOptions(within(screen.getByRole('dialog')).getByRole('combobox', { name: 'Kind' }), KINDS[0]!);
    await u.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Add location' }));
    await waitFor(() => expect(within(screen.getByRole('dialog')).getByText(ARCHIVED_ON_CREATE)).toBeInTheDocument());
    expect(within(screen.getByRole('dialog')).getByText('Not added')).toBeInTheDocument();
    await u.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Cancel' }));
    expect(screen.queryByRole('dialog')).toBeNull();
    await waitFor(() => expect(calls).toHaveLength(4));
    await u.click(await screen.findByRole('button', { name: 'Restore warehouse' }));
    await waitFor(() => expect(screen.queryByText(/This warehouse was archived on/)).toBeNull());
    expect(screen.queryByRole('dialog')).toBeNull();
  });

  // S-4 RED-2 L-2 (shipped): the Delete location dialog had the same shape (`deleting !== null && canDelete && !warehouseArchived`).
  it('Delete location: stays open; its Delete shows the archived refusal under Not deleted with Close only; after Close and Restore warehouse it does not come back by itself', async () => {
    const s = held();
    const calls = stubFetch([s.reply, json(200, ARCHIVED_MAIN), refused('warehouse.is_archived', ARCHIVED_ON_DELETE), coll([A1]), json(200, RESTORED), json(200, RESTORED)]);
    const u = userEvent.setup();
    renderWired(<WarehousePage initial={MAIN} canEdit canRestore locations={{ initial: { kind: 'ok', locations: [A1] }, canEdit: false, canRestore: true, canCreate: false, canDelete: true }} />);
    await saveWarehouse(u);
    await u.click(screen.getByRole('button', { name: 'Delete A1' }));
    await act(async () => s.release(json(200, RESTORED)));
    await waitFor(() => expect(screen.getByText(/This warehouse was archived on/)).toBeInTheDocument());
    expect(screen.queryByRole('dialog')).not.toBeNull();
    expect(screen.getByRole('dialog').contains(document.activeElement)).toBe(true);
    await u.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Delete location' }));
    await waitFor(() => expect(within(screen.getByRole('dialog')).getByText(ARCHIVED_ON_DELETE)).toBeInTheDocument());
    expect(within(screen.getByRole('dialog')).getByText('Not deleted')).toBeInTheDocument();
    expect(within(screen.getByRole('dialog')).getAllByRole('button').map((b) => b.textContent)).toEqual(['Close']);
    await u.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Close' }));
    expect(screen.queryByRole('dialog')).toBeNull();
    await waitFor(() => expect(calls).toHaveLength(4));
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET', 'DELETE', 'GET']);
    await u.click(await screen.findByRole('button', { name: 'Restore warehouse' }));
    await waitFor(() => expect(screen.queryByText(/This warehouse was archived on/)).toBeNull());
    expect(screen.queryByRole('dialog')).toBeNull();
  });
});

// The heading fallback of the Locations section's focus return (`focusOpener`, "else the heading"): the button that opened
// the dialog is gone (the page turned archived while the dialog was open, so the row's Edit and Add location unmounted),
// and the dialog's request still succeeds (the warehouse was restored elsewhere meanwhile). Focus then goes to the
// Locations heading, BEFORE the sentence is written. The spy tells a call inside the dialog from the page's.
describe('/warehouses/[id] — focus falls back to the Locations heading when the dialog\'s opener is gone', () => {
  const pageRegion = () => document.querySelector<HTMLElement>('section > [role="status"]')!;
  const spyFocus = () => {
    const seen: { el: string; region: string }[] = [];
    const real = HTMLElement.prototype.focus;
    const spy = vi.spyOn(HTMLElement.prototype, 'focus').mockImplementation(function (this: HTMLElement, opts?: FocusOptions) {
      seen.push({ el: (this.closest('[role="dialog"]') ? 'dialog:' : '') + `${this.tagName}:${this.getAttribute('aria-label') ?? this.textContent}`, region: pageRegion().textContent ?? '' });
      real.call(this, opts);
    });
    return { seen, spy };
  };
  const locationsHeading = () => screen.getByRole('heading', { level: 2, name: 'Locations' });

  it('Edit location: the page reads archived across the dialog, its Save answers 200 → the Locations heading takes focus while the region is still empty; "A2 was saved." follows', async () => {
    const s = held();
    const saved = { ...A1, code: 'A2' };
    stubFetch([s.reply, json(200, ARCHIVED_MAIN), json(200, saved), coll([saved])]);
    const u = userEvent.setup();
    renderWired(<WarehousePage initial={MAIN} canEdit canRestore locations={{ initial: { kind: 'ok', locations: [A1] }, canEdit: true, canRestore: true, canCreate: false }} />);
    await saveWarehouse(u);
    await u.click(screen.getByRole('button', { name: 'Edit A1' }));
    const code = within(screen.getByRole('dialog')).getByRole('textbox', { name: 'Code' });
    await u.clear(code);
    await u.type(code, 'A2');
    await act(async () => s.release(json(200, RESTORED)));
    await waitFor(() => expect(screen.getByText(/This warehouse was archived on/)).toBeInTheDocument());
    expect(screen.queryByRole('button', { name: 'Edit A1' })).toBeNull();
    const f = spyFocus();
    try {
      await u.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Save' }));
      await waitFor(() => expect(pageRegion().textContent).toBe('A2 was saved.'));
    } finally {
      f.spy.mockRestore();
    }
    const onHeading = f.seen.filter((c) => c.el === 'H2:Locations');
    expect(onHeading.length).toBeGreaterThan(0);
    expect(onHeading[0]!.region).toBe('');
    await waitFor(() => expect(document.activeElement).toBe(locationsHeading()));
  });

  // Add location's press does not empty the region (no FE-R9 clear on that dialog, shipped), so the region still holds the
  // warehouse Save's sentence when the heading takes focus: what is pinned is that "B9 was added." comes after the focus.
  it('Add location: the page reads archived across the dialog, its Add answers 201 → the Locations heading takes focus before "B9 was added." is written', async () => {
    const s = held();
    const made: StorageLocation = { id: '66666666-6666-4666-8666-666666666666', code: 'B9', kind: KINDS[0]!, archived_at: null };
    stubFetch([s.reply, json(200, ARCHIVED_MAIN), json(201, made), coll([A1, made])]);
    const u = userEvent.setup();
    renderWired(<WarehousePage initial={MAIN} canEdit canRestore locations={{ initial: { kind: 'ok', locations: [A1] }, canEdit: false, canRestore: true, canCreate: true }} />);
    await saveWarehouse(u);
    await u.click(screen.getAllByRole('button', { name: 'Add location' })[0]!);
    await u.type(within(screen.getByRole('dialog')).getByRole('textbox', { name: 'Code' }), 'B9');
    await u.selectOptions(within(screen.getByRole('dialog')).getByRole('combobox', { name: 'Kind' }), KINDS[0]!);
    await act(async () => s.release(json(200, RESTORED)));
    await waitFor(() => expect(screen.getByText(/This warehouse was archived on/)).toBeInTheDocument());
    expect(screen.getAllByRole('button', { name: 'Add location' }).every((b) => b.closest('[role="dialog"]') !== null)).toBe(true);
    const f = spyFocus();
    try {
      await u.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Add location' }));
      await waitFor(() => expect(pageRegion().textContent).toBe('B9 was added.'));
    } finally {
      f.spy.mockRestore();
    }
    const onHeading = f.seen.filter((c) => c.el === 'H2:Locations');
    expect(onHeading.length).toBeGreaterThan(0);
    expect(onHeading[0]!.region).toBe('MAIN was saved.');
    await waitFor(() => expect(document.activeElement).toBe(locationsHeading()));
  });
});
