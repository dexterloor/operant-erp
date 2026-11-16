import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ refresh: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ refresh: nav.refresh }) }));
import { WarehousePage } from '@/app/(app)/warehouses/[id]/WarehousePage';
import type { StorageLocation, Warehouse } from '@/lib/api/warehouses';
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
});
