import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ replace: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ replace: nav.replace }), usePathname: () => '/warehouses' }));
import { WarehousesList } from '@/app/(app)/warehouses/WarehousesList';
import type { Warehouse } from '@/lib/api/warehouses';
import { formatDate } from '@/lib/format/dates';
import { TZ, json, page, renderWired, stubFetch } from './harness';

// warehouses-ux-copy.md B-1, B-2; Revision 7 A-17 (`header`, `as-served`, struck), R3-1 (no Back to… before a view has
// loaded), R3-7 (no New warehouse / Create warehouse action in 4.1), R3-8 (the interim archived empty state).

afterEach(() => {
  vi.unstubAllGlobals();
  nav.replace.mockReset();
});

const W = (id: string, code: string, name: string, archived_at: string | null = null): Warehouse => ({ id, code, name, country_code: 'GB', archived_at });
const A = W('a1b2c3d4-0000-4000-8000-000000000001', 'MAIN', 'Main warehouse');
const B = W('a1b2c3d4-0000-4000-8000-000000000002', 'NORTH', 'North depot');
const OLD = W('a1b2c3d4-0000-4000-8000-000000000003', 'ZED', 'Old one', '2026-01-02T10:00:00Z');
const OLDER = W('a1b2c3d4-0000-4000-8000-000000000004', 'ALPHA', 'Older one', '2026-02-02T10:00:00Z');
const ok = (warehouses: Warehouse[], tenantHasRows = warehouses.length > 0) => ({ kind: 'ok', warehouses, tenantHasRows }) as const;
const region = () => screen.getByRole('heading', { level: 1 }).closest('section')!.querySelector<HTMLElement>(':scope > [role="status"]')!;

describe('/warehouses — the live view (B-1)', () => {
  it('Code · Name · Country; the code links to /warehouses/[id]; the country as sent', () => {
    renderWired(<WarehousesList initial={ok([A, B])} />);
    expect(screen.getByRole('heading', { level: 1, name: 'Warehouses' })).toBeInTheDocument();
    expect(screen.getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Code', 'Name', 'Country']);
    const row = screen.getByText('Main warehouse').closest('tr')!;
    expect(within(row).getByRole('link', { name: 'MAIN' })).toHaveAttribute('href', `/warehouses/${A.id}`);
    expect(within(row).getByText('GB')).toBeInTheDocument();
  });

  it('offers no New warehouse or Create warehouse action (CreateWarehouse is not wired, R3-7)', () => {
    renderWired(<WarehousesList initial={ok([], false)} />);
    expect(screen.getByText('No warehouses yet.')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /warehouse/i })).toBeNull();
    expect(screen.queryByRole('link', { name: /new|create/i })).toBeNull();
  });

  it('search is a case-insensitive prefix on code or name, and the settled sentence names the query', async () => {
    const u = userEvent.setup();
    renderWired(<WarehousesList initial={ok([A, B])} />);
    await u.type(screen.getByRole('searchbox', { name: 'Search by code or name' }), 'nor');
    expect(screen.queryByText('Main warehouse')).toBeNull();
    expect(screen.getByText('North depot')).toBeInTheDocument();
    await waitFor(() => expect(region()).toHaveTextContent('1 warehouse shown. Code or name starts with “nor”.'));
    await u.clear(screen.getByRole('searchbox'));
    await u.type(screen.getByRole('searchbox'), 'main w');
    expect(screen.getByText('Main warehouse')).toBeInTheDocument();
  });

  it('every warehouse archived: the U-6 sentence and View archived warehouses', async () => {
    stubFetch([json(200, page([OLD]))]);
    const u = userEvent.setup();
    renderWired(<WarehousesList initial={ok([], true)} />);
    expect(screen.getByText("No warehouses to show. Every warehouse here has been deleted, and they're in the archived warehouses.")).toBeInTheDocument();
    await u.click(screen.getByRole('button', { name: 'View archived warehouses' }));
    expect(await screen.findByText('Old one')).toBeInTheDocument();
    expect(nav.replace).toHaveBeenLastCalledWith('/warehouses?archived=true', { scroll: false });
  });
});

describe('/warehouses?archived=true — the Archived view (B-2)', () => {
  it('heading, Back to warehouses, rows struck, Archived on, server order kept, no caption', async () => {
    stubFetch([json(200, page([OLD, OLDER]))]);
    const u = userEvent.setup();
    renderWired(<WarehousesList initial={ok([A])} />);
    await u.click(screen.getByRole('button', { name: 'Archived' }));
    expect(await screen.findByRole('heading', { level: 1, name: 'Archived warehouses' })).toBeInTheDocument();
    expect(nav.replace).toHaveBeenLastCalledWith('/warehouses?archived=true', { scroll: false });
    expect(screen.getByRole('button', { name: 'Back to warehouses' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Archived' })).toBeNull();
    expect(screen.getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Code', 'Name', 'Country', 'Archived on']);
    // As served (ZED first, though ALPHA was archived later and sorts first by code): no client re-sort, no caption.
    expect([...document.querySelectorAll('tbody tr')].map((r) => r.querySelector('a')?.textContent)).toEqual(['ZED', 'ALPHA']);
    expect(document.querySelector('caption')).toBeNull();
    expect(document.querySelector('tbody tr')!.className).not.toBe('');
    expect(within(document.querySelector('tbody tr')!).getByText(formatDate(OLD.archived_at!, TZ))).toBeInTheDocument();
    expect(screen.getByRole('searchbox', { name: 'Search archived warehouses by code or name' })).toBeInTheDocument();
  });

  it('empty: the interim sentence without "can be restored from here" (R3-8), no action', () => {
    renderWired(<WarehousesList initial={ok([], true)} initialArchived />);
    expect(screen.getByText('No archived warehouses. Warehouses that are deleted appear here.')).toBeInTheDocument();
    expect(document.body).not.toHaveTextContent(/restore/i);
  });

  it('Back to warehouses returns to the live view and the plain address', async () => {
    stubFetch([json(200, page([A]))]);
    const u = userEvent.setup();
    renderWired(<WarehousesList initial={ok([OLD])} initialArchived />);
    await u.click(screen.getByRole('button', { name: 'Back to warehouses' }));
    expect(await screen.findByText('Main warehouse')).toBeInTheDocument();
    expect(nav.replace).toHaveBeenLastCalledWith('/warehouses', { scroll: false });
  });

  it('a failed first load of the Archived view offers no Back to warehouses (R3-1)', () => {
    renderWired(<WarehousesList initial={{ kind: 'unreachable' }} initialArchived />);
    expect(screen.getByRole('button', { name: 'Retry' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Back to warehouses' })).toBeNull();
  });

  it('FE-R3 H-1: after a failed switch, a successful banner Retry moves focus to Back to warehouses, never to <body>', async () => {
    stubFetch([json(503, {}), json(200, page([OLD]))]);
    const u = userEvent.setup();
    renderWired(<WarehousesList initial={ok([A])} />);
    await u.click(screen.getByRole('button', { name: 'Archived' }));
    expect(await screen.findByText("The list couldn't be updated, so it still shows the earlier results.")).toBeInTheDocument();
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    expect(await screen.findByText('Old one')).toBeInTheDocument();
    await waitFor(() => expect(screen.getByRole('button', { name: 'Back to warehouses' })).toHaveFocus());
  });
});
