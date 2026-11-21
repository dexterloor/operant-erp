import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ refresh: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ refresh: nav.refresh }) }));
import { ItemPage } from '@/app/(app)/items/[id]/ItemPage';
import type { Item } from '@/lib/api/items';
import { empty, json, refused, renderWired, stubFetch, textsOf } from './harness';

// FE-R9 (frontend-residuals.md): a sentence written again word for word with nothing written between is no change to the
// region, so a screen reader is given nothing. Here the record's state changes elsewhere and the page learns it through a
// re-read that writes nothing (the Delete dialog's Close, a Retry); the next press then says what the last one said.

afterEach(() => {
  vi.unstubAllGlobals();
  nav.refresh.mockClear();
});

const IID = '7b2c3d4e-5f6a-4b7c-8d9e-0f1a2b3c4d5e';
const BOLT: Item = {
  id: IID,
  sku: 'A-1',
  name: 'Bolt',
  unit_of_measure_code: 'EA',
  reorder_point: '10.000000',
  reorder_quantity: '5.000000',
  safety_stock: '0.000000',
  supplier: null,
  archived_at: null,
  holds_stock: false,
};
const ARCHIVED: Item = { ...BOLT, archived_at: '2026-11-05T10:00:00Z' };
const region = () => document.querySelector<HTMLElement>('section > [role="status"]')!;
const dialog = () => screen.getByRole('dialog');
const RESTORED = 'A-1 was restored.';
const AFTER = "A-1 was deleted. It's now in the archived items.";
const I3 = "This item is already archived. It's in the archived items.";
const ARCHIVED_BANNER = /This item was archived on/;
const show = (i: Item) => renderWired(<ItemPage initial={i} canEdit canRestore canDelete />);

describe('/items/[id] — a repeated restore or delete sentence is written again (FE-R9)', () => {
  // This route is now covered by both clears: the Delete press empties the region, and so does the second Restore press
  // (fe-r9-red.md M-1). Red when both are dropped.
  it('Restore, an archive made elsewhere learned through the Delete dialog\'s Close, Restore again: spoken both times', async () => {
    const calls = stubFetch([json(200, BOLT), json(200, BOLT), refused('inventory_item.is_archived', I3), json(200, ARCHIVED), json(200, BOLT), json(200, BOLT)]);
    const u = userEvent.setup();
    show(ARCHIVED);
    const rec = textsOf(region());
    await u.click(screen.getByRole('button', { name: 'Restore item' }));
    await waitFor(() => expect(region()).toHaveTextContent(RESTORED));
    await u.click(await screen.findByRole('button', { name: 'Delete item' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete item' }));
    await waitFor(() => expect(within(dialog()).getByText('Not deleted')).toHaveFocus());
    await u.click(within(dialog()).getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(screen.getByText(ARCHIVED_BANNER)).toBeInTheDocument());
    await u.click(screen.getByRole('button', { name: 'Restore item' }));
    await waitFor(() => expect(screen.queryByText(ARCHIVED_BANNER)).toBeNull());
    await waitFor(() => expect(rec.texts).toEqual(['', RESTORED, '', RESTORED]));
    expect(calls.map((c) => c.method)).toEqual(['POST', 'GET', 'DELETE', 'GET', 'POST', 'GET']);
    rec.stop();
  });

  // fe-r9-red.md M-1 (Probe P1): no Delete press at all. The Restore press's own re-read fails, so the page shows a region
  // error; the item is archived elsewhere and Retry learns it, writing nothing; Restore again says what the first said.
  // What keeps it from repeating is the Restore press emptying the region.
  it('Restore (its re-read fails), an archive made elsewhere learned through Retry, Restore again: spoken both times', async () => {
    const calls = stubFetch([json(200, BOLT), json(503, {}), json(200, ARCHIVED), json(200, BOLT), json(200, BOLT)]);
    const u = userEvent.setup();
    show(ARCHIVED);
    const rec = textsOf(region());
    await u.click(screen.getByRole('button', { name: 'Restore item' }));
    await waitFor(() => expect(region()).toHaveTextContent(RESTORED));
    await u.click(await screen.findByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(screen.getByText(ARCHIVED_BANNER)).toBeInTheDocument());
    await u.click(screen.getByRole('button', { name: 'Restore item' }));
    await waitFor(() => expect(screen.queryByText(ARCHIVED_BANNER)).toBeNull());
    await waitFor(() => expect(rec.texts).toEqual(['', RESTORED, '', RESTORED]));
    expect(calls.map((c) => c.method)).toEqual(['POST', 'GET', 'GET', 'POST', 'GET']);
    rec.stop();
  });

  // The dialog empties the region only on a press that sends: Cancel sends nothing and leaves the region as it was.
  it('Delete item, then Cancel: nothing is sent and the region keeps its sentence', async () => {
    const calls = stubFetch([json(200, BOLT), json(200, BOLT)]);
    const u = userEvent.setup();
    show(ARCHIVED);
    await u.click(screen.getByRole('button', { name: 'Restore item' }));
    await waitFor(() => expect(region()).toHaveTextContent(RESTORED));
    const rec = textsOf(region());
    await u.click(await screen.findByRole('button', { name: 'Delete item' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Cancel' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(rec.texts).toEqual([RESTORED]);
    expect(calls.map((c) => c.method)).toEqual(['POST', 'GET']);
    rec.stop();
  });

  it('Delete (its re-read fails), a restore made elsewhere learned through Retry, Delete again: spoken both times', async () => {
    const calls = stubFetch([empty(204), json(503, {}), json(200, BOLT), empty(204), json(200, ARCHIVED)]);
    const u = userEvent.setup();
    show(BOLT);
    const rec = textsOf(region());
    await u.click(screen.getByRole('button', { name: 'Delete item' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete item' }));
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    await u.click(await screen.findByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(screen.queryByText(ARCHIVED_BANNER)).toBeNull());
    await u.click(await screen.findByRole('button', { name: 'Delete item' }));
    await u.click(within(dialog()).getByRole('button', { name: 'Delete item' }));
    await waitFor(() => expect(screen.getByText(ARCHIVED_BANNER)).toBeInTheDocument());
    await waitFor(() => expect(rec.texts).toEqual(['', AFTER, '', AFTER]));
    expect(calls.map((c) => c.method)).toEqual(['DELETE', 'GET', 'GET', 'DELETE', 'GET']);
    rec.stop();
  });
});
