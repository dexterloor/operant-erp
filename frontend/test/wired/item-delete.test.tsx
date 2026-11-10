import { StrictMode, type ReactElement } from 'react';
import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ refresh: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ refresh: nav.refresh }) }));
import { ItemPage } from '@/app/(app)/items/[id]/ItemPage';
import { DELETE_ITEM } from '@/copy/items';
import type { Item } from '@/lib/api/items';
import { classify } from '@/lib/bff/problem';
import { empty, json, problem, refused, renderWired, stubFetch } from './harness';

// Items I-3 (items-i3-design.md Revision 1 — FINAL §1 as corrected by items-i3-red.md "Final RED — on Revision 1";
// items-i3-ux.md Revision 1 — FINAL F-1, F-2; items-i3-po-sec.md I3-1…I3-9): **Delete item** on a live item's page for a
// DeleteItem holder. The shared danger dialog (initial focus Cancel), the holds-stock line first in its description when
// `holds_stock` is true, F-1's body verbatim. After a 204 the page stays and turns archived (focus h1, "After" spoken,
// one refresh). A refusal shows under "Not deleted"; I3 / empty message / 404 leave Close only; P1, 403 and an
// interrupted press keep Delete item. Any other close after an answer re-reads; a live re-read changes nothing (the
// typed edits stay, M-5; the region error stays, final RED L-4); an archived one is committed.

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
const STOCKED: Item = { ...BOLT, holds_stock: true };
const ARCHIVED: Item = { ...BOLT, archived_at: '2026-11-05T10:00:00Z' };
const region = () => document.querySelector<HTMLElement>('section > [role="status"]')!;
const dialog = () => screen.getByRole('dialog');
const deleteButton = () => screen.queryByRole('button', { name: 'Delete item' });
const confirm = () => within(dialog()).getByRole('button', { name: 'Delete item' });
const field = (name: string) => screen.queryByRole('textbox', { name });
/** items-ux-copy.md FINAL F-1, verbatim (I3-2). */
const BODY =
  "It moves to the archived items. Until it's restored, it can't be added to orders or price lists, it isn't due for reordering, and any stock of it can't be counted or moved between locations. Orders that already name it keep showing it, and can still be received and shipped.";
/** items-i3-ux.md Revision 1 — FINAL F-1 stock line (L-2 as amended; the final RED §G chose it). */
const HOLDS = 'This item still holds stock in at least one location. Stock in quarantine is included.';
const AFTER = "A-1 was deleted. It's now in the archived items.";
const INTERRUPTED = "We can't tell whether A-1 was deleted. Press Delete item to try again.";
const I3 = "This item is already archived. It's in the archived items.";
/** `TransactionContention::MESSAGE` (backend/src/Platform/Http/Problem/TransactionContention.php), 4.6 L-4. */
const CONTENTION = 'Some of the same records were being changed at the same moment. Nothing was saved; try again.';
const REREAD_FAILED = "We couldn't load this item again, so what's shown may be out of date.";
const ARCHIVED_BANNER = /This item was archived on/;

const show = (o: { i?: Item; canDelete?: boolean; canEdit?: boolean } = {}) =>
  renderWired(<ItemPage initial={o.i ?? BOLT} canEdit={o.canEdit ?? true} canRestore canDelete={o.canDelete ?? true} />);
const openAndConfirm = async (u: ReturnType<typeof userEvent.setup>) => {
  await u.click(deleteButton()!);
  await u.click(confirm());
};
const held = () => {
  let release!: (r: Response) => void;
  const p = new Promise<Response>((res) => (release = res));
  return { reply: () => p, release: (r: Response) => release(r) };
};
const tick = async (ms = 50) => {
  await act(async () => {
    await new Promise((t) => setTimeout(t, ms));
  });
};

describe('/items/[id] — Delete item: the offer and the dialog (F-1, I3-2…I3-4)', () => {
  it('offered on a live item to a DeleteItem holder only, never on an archived one', () => {
    const a = show({ canDelete: false });
    expect(deleteButton()).toBeNull();
    a.unmount();
    const b = show({ i: ARCHIVED });
    expect(deleteButton()).toBeNull();
    b.unmount();
    show();
    expect(deleteButton()).toBeInTheDocument();
  });

  it('the dialog: "Delete {sku} — {name}?", F-1 body verbatim, focus on Cancel; Cancel sends nothing and re-reads nothing', async () => {
    const calls = stubFetch([]);
    const u = userEvent.setup();
    show();
    await u.click(deleteButton()!);
    expect(within(dialog()).getByRole('heading', { name: 'Delete A-1 — Bolt?' })).toBeInTheDocument();
    expect(within(dialog()).getByText(BODY)).toBeInTheDocument();
    await waitFor(() => expect(within(dialog()).getByRole('button', { name: 'Cancel' })).toHaveFocus());
    await u.click(within(dialog()).getByRole('button', { name: 'Cancel' }));
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(calls).toEqual([]);
  });

  it('holds_stock true: the stock line is its own paragraph, first in the description, before the body', async () => {
    const u = userEvent.setup();
    show({ i: STOCKED });
    await u.click(deleteButton()!);
    const description = document.getElementById(dialog().getAttribute('aria-describedby')!)!;
    const paragraphs = [...description.querySelectorAll('p')].map((p) => p.textContent);
    expect(paragraphs).toEqual([HOLDS, BODY]);
    expect(description.textContent!.startsWith(HOLDS)).toBe(true);
  });

  it('holds_stock false: no stock line; the description is exactly the body', async () => {
    const u = userEvent.setup();
    show();
    await u.click(deleteButton()!);
    const description = document.getElementById(dialog().getAttribute('aria-describedby')!)!;
    expect(description.textContent).toBe(BODY);
    expect(screen.queryByText(HOLDS)).toBeNull();
  });

  it('the stock line names no stock figure, place or sale word (I3-3, M-4 scan)', () => {
    expect(DELETE_ITEM.holdsStock).toBe(HOLDS);
    expect(/in stock|available|can be sold|pick|sell|\d|warehouse|\bbin\b/i.test(DELETE_ITEM.holdsStock)).toBe(false);
  });

  it('holds_stock is rendered nowhere on the page outside the dialog, open or closed (I3-4, I3-6)', async () => {
    const u = userEvent.setup();
    show({ i: STOCKED });
    expect(document.body.textContent).not.toContain(HOLDS);
    expect(document.body.textContent).not.toMatch(/holds stock|holds_stock/i);
    await u.click(deleteButton()!);
    expect(screen.getAllByText(HOLDS)).toHaveLength(1);
    expect(dialog()).toContainElement(screen.getByText(HOLDS));
    await u.click(within(dialog()).getByRole('button', { name: 'Cancel' }));
    expect(document.body.textContent).not.toContain(HOLDS);
  });

  // §5.3 bullet 1 (RED text, final): the line follows the newest committed reading. A Retry started before the dialog
  // opened can commit while it is open (ruling 1(c): the fixture uses a held Retry only).
  const flipWhileOpen = async (from: Item, to: Item) => {
    const retry = held();
    stubFetch([json(200, { ...from, name: 'Boltx' }), json(503, {}), retry.reply]);
    const u = userEvent.setup();
    show({ i: from });
    await u.type(field('Name')!, 'x');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await u.click(await screen.findByRole('button', { name: 'Retry' }));
    await u.click(deleteButton()!);
    expect(screen.queryAllByText(HOLDS)).toHaveLength(from.holds_stock ? 1 : 0);
    retry.release(json(200, { ...to, name: 'Boltx' }));
    await tick();
  };

  it('a held Retry committing while the dialog is open flips the line on (§5.3)', async () => {
    await flipWhileOpen(BOLT, STOCKED);
    expect(within(dialog()).getByText(HOLDS)).toBeInTheDocument();
  });

  it('…and off (§5.3, the other direction)', async () => {
    await flipWhileOpen(STOCKED, BOLT);
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    expect(within(dialog()).queryByText(HOLDS)).toBeNull();
  });
});

describe('/items/[id] — Delete item: answers (F-2)', () => {
  it('a 204: DELETE with no body and no key, the re-read turns the page archived, Restore item, no form, focus h1, "After" once, one refresh', async () => {
    const calls = stubFetch([empty(204), json(200, ARCHIVED)]);
    const u = userEvent.setup();
    show();
    await openAndConfirm(u);
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    expect(calls.map((c) => c.method)).toEqual(['DELETE', 'GET']);
    expect(calls[0]!.url).toContain(`/bff/api/items/${IID}`);
    expect(calls[0]!.body).toBeNull();
    expect(calls[0]!.headers.get('idempotency-key')).toBeNull();
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(screen.getByRole('heading', { level: 1 })).toHaveFocus();
    expect(screen.getByText(ARCHIVED_BANNER)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Restore item' })).toBeInTheDocument();
    expect(deleteButton()).toBeNull();
    expect(field('Name')).toBeNull();
    expect(nav.refresh).toHaveBeenCalledTimes(1);
    expect(region().textContent).toBe(AFTER);
  });

  it('typed edits, then a 204: the archived page shows the served SKU (the edits are discarded with the form)', async () => {
    stubFetch([empty(204), json(200, ARCHIVED)]);
    const u = userEvent.setup();
    show();
    await u.type(field('SKU')!, 'Z');
    await openAndConfirm(u);
    await waitFor(() => expect(screen.getByText(ARCHIVED_BANNER)).toBeInTheDocument());
    expect(field('SKU')).toBeNull();
    expect(screen.getByText('A-1', { selector: 'dd' })).toBeInTheDocument();
  });

  it('a 204 whose re-read fails: region error, no Delete item, no form; Retry → archived → Restore → editable again (4.6 M-1, L-4)', async () => {
    const calls = stubFetch([empty(204), json(503, {}), json(200, ARCHIVED), json(200, BOLT), json(200, BOLT)]);
    const u = userEvent.setup();
    show();
    await openAndConfirm(u);
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    expect(await screen.findByText(REREAD_FAILED)).toBeInTheDocument();
    expect(calls.map((c) => c.method)).toEqual(['DELETE', 'GET']);
    expect(deleteButton()).toBeNull();
    expect(field('Name')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Save' })).toBeNull();
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await u.click(await screen.findByRole('button', { name: 'Restore item' }));
    await waitFor(() => expect(screen.queryByText(ARCHIVED_BANNER)).toBeNull());
    expect(field('Name')).toBeInTheDocument();
    expect(deleteButton()).toBeInTheDocument();
  });

  it('409 I3: "Not deleted" + the sentence as sent, heading focused; Delete removed, Close only; Close re-reads once and the h1 takes focus (H-1 a)', async () => {
    const calls = stubFetch([refused('inventory_item.is_archived', I3), json(200, ARCHIVED)]);
    const u = userEvent.setup();
    show();
    await openAndConfirm(u);
    await waitFor(() => expect(within(dialog()).getByText('Not deleted')).toHaveFocus());
    expect(dialog()).toHaveTextContent(I3);
    expect(within(dialog()).queryByRole('button', { name: 'Delete item' })).toBeNull();
    expect(within(dialog()).queryByRole('button', { name: 'Cancel' })).toBeNull();
    await u.click(within(dialog()).getByRole('button', { name: 'Close' }));
    await waitFor(() => expect(screen.getByText(ARCHIVED_BANNER)).toBeInTheDocument());
    expect(calls.map((c) => c.method)).toEqual(['DELETE', 'GET']);
    expect(screen.getByRole('heading', { level: 1 })).toHaveFocus();
    expect(field('Name')).toBeNull();
    expect(nav.refresh).toHaveBeenCalledTimes(1);
  });

  // Mutation #11 stayed green on the two tests above: their re-read answers before the dialog hands focus back, so the
  // dialog's own fallback (the h1) hides a missing focus move. Here the re-read is held until focus is back on Delete
  // item; the archived answer then unmounts that button under the person's focus.
  it('409 I3 → Close with the re-read held until focus is back on Delete item: the archived answer moves focus to the h1, never <body> (H-1)', async () => {
    const reread = held();
    stubFetch([refused('inventory_item.is_archived', I3), reread.reply]);
    const u = userEvent.setup();
    show();
    await openAndConfirm(u);
    await u.click(await within(dialog()).findByRole('button', { name: 'Close' }));
    await waitFor(() => expect(deleteButton()).toHaveFocus());
    reread.release(json(200, ARCHIVED));
    await waitFor(() => expect(screen.getByText(ARCHIVED_BANNER)).toBeInTheDocument());
    await tick();
    expect(screen.getByRole('heading', { level: 1 })).toHaveFocus();
    expect(document.activeElement).not.toBe(document.body);
  });

  it('interrupted, Escape, the re-read finds it archived (the press committed): the h1 takes focus, never <body> (H-1 b)', async () => {
    stubFetch([problem(503, 'x'), json(200, ARCHIVED)]);
    const u = userEvent.setup();
    show();
    await openAndConfirm(u);
    await waitFor(() => expect(dialog()).toHaveTextContent(INTERRUPTED));
    await u.keyboard('{Escape}');
    await waitFor(() => expect(screen.getByText(ARCHIVED_BANNER)).toBeInTheDocument());
    expect(screen.getByRole('heading', { level: 1 })).toHaveFocus();
    expect(document.activeElement).not.toBe(document.body);
  });

  it('P1 with the server sentence: Delete item stays; a second press sends a second DELETE', async () => {
    const calls = stubFetch([refused('transaction.contention', CONTENTION), empty(204), json(200, ARCHIVED)]);
    const u = userEvent.setup();
    show();
    await openAndConfirm(u);
    await waitFor(() => expect(within(dialog()).getByText('Not deleted')).toHaveFocus());
    expect(dialog()).toHaveTextContent(CONTENTION);
    expect(within(dialog()).queryByRole('button', { name: 'Close' })).toBeNull();
    await u.click(confirm());
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    expect(calls.map((c) => c.method)).toEqual(['DELETE', 'DELETE', 'GET']);
  });

  it('a 409 with an empty message: REFUSED_WITHOUT_MESSAGE, Close only', async () => {
    stubFetch([refused('x', '')]);
    const u = userEvent.setup();
    show();
    await openAndConfirm(u);
    await waitFor(() => expect(dialog()).toHaveTextContent("This change wasn't made. Reload the page to see where things stand, then try again."));
    expect(within(dialog()).queryByRole('button', { name: 'Delete item' })).toBeNull();
    expect(within(dialog()).getByRole('button', { name: 'Close' })).toBeInTheDocument();
  });

  it('a 403 names "Delete and restore items" (B-9); Delete item stays (L-1, ruling 1(b))', async () => {
    stubFetch([problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    show();
    await openAndConfirm(u);
    await waitFor(() => expect(within(dialog()).getByText('Not deleted')).toHaveFocus());
    expect(dialog()).toHaveTextContent('This needs the Delete and restore items permission, which you no longer have. Your workspace administrator can grant it.');
    expect(within(dialog()).getByRole('button', { name: 'Delete item' })).toBeInTheDocument();
  });

  it('a 404: "We couldn’t find that." under "Not deleted", Close only (0b)', async () => {
    stubFetch([problem(404, 'not-found')]);
    const u = userEvent.setup();
    show();
    await openAndConfirm(u);
    await waitFor(() => expect(dialog()).toHaveTextContent("We couldn't find that. It may have been deleted, or you may not have access."));
    expect(within(dialog()).queryByRole('button', { name: 'Delete item' })).toBeNull();
    expect(within(dialog()).getByRole('button', { name: 'Close' })).toBeInTheDocument();
  });

  it('a lost connection: "We couldn’t reach the server." + the interrupted sentence; Delete item stays', async () => {
    stubFetch([
      () => {
        throw new TypeError('Failed to fetch');
      },
    ]);
    const u = userEvent.setup();
    show();
    await openAndConfirm(u);
    await waitFor(() => expect(dialog()).toHaveTextContent("We couldn't reach the server."));
    expect(dialog()).toHaveTextContent(INTERRUPTED);
    expect(within(dialog()).getByRole('button', { name: 'Delete item' })).toBeInTheDocument();
  });

  it('a 500: "Something went wrong on our side." + the interrupted sentence; Delete item stays', async () => {
    stubFetch([problem(500, 'x')]);
    const u = userEvent.setup();
    show();
    await openAndConfirm(u);
    await waitFor(() => expect(dialog()).toHaveTextContent('Something went wrong on our side.'));
    expect(dialog()).toHaveTextContent(INTERRUPTED);
    expect(within(dialog()).getByRole('button', { name: 'Delete item' })).toBeInTheDocument();
  });

  it('a 429 (classified rate-limited) takes the 5xx row: "Something went wrong on our side." + interrupted', async () => {
    expect(await classify(problem(429, 'too-many-attempts'))).toEqual({ kind: 'rate-limited' });
    stubFetch([problem(429, 'too-many-attempts')]);
    const u = userEvent.setup();
    show();
    await openAndConfirm(u);
    await waitFor(() => expect(dialog()).toHaveTextContent('Something went wrong on our side.'));
    expect(dialog()).toHaveTextContent(INTERRUPTED);
  });

  it('a 401: nothing is rendered in the dialog (the caller navigates once)', async () => {
    stubFetch([new Response(JSON.stringify({ type: 'urn:erp:bff:session-ended', title: 'x', status: 401 }), { status: 401, headers: { 'content-type': 'application/problem+json' } })]);
    const u = userEvent.setup();
    show();
    await openAndConfirm(u);
    await tick();
    expect(within(dialog()).queryByText('Not deleted')).toBeNull();
    expect(dialog()).not.toHaveTextContent(INTERRUPTED);
    expect(dialog()).not.toHaveTextContent('Something went wrong on our side.');
  });

  it('Escape after a refusal re-reads (R1-8)', async () => {
    const calls = stubFetch([refused('transaction.contention', CONTENTION), json(200, BOLT)]);
    const u = userEvent.setup();
    show();
    await openAndConfirm(u);
    await waitFor(() => expect(within(dialog()).getByText('Not deleted')).toHaveFocus());
    await u.keyboard('{Escape}');
    await waitFor(() => expect(calls.map((c) => c.method)).toEqual(['DELETE', 'GET']));
  });

  it('a Save renaming the SKU lands while the delete is in flight: "After" names the SKU on screen (RED L-2)', async () => {
    const patch = held();
    const del = held();
    stubFetch([patch.reply, del.reply, json(200, { ...BOLT, sku: 'A-2' }), json(200, { ...ARCHIVED, sku: 'A-2' })]);
    const u = userEvent.setup();
    show();
    const sku = field('SKU')!;
    await u.clear(sku);
    await u.type(sku, 'A-2');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await openAndConfirm(u);
    patch.release(json(200, { ...BOLT, sku: 'A-2' }));
    await tick();
    del.release(empty(204));
    await tick();
    await tick();
    expect(region().textContent).toBe("A-2 was deleted. It's now in the archived items.");
  });

  it('a 204 whose re-read reads the item live (restored in between): the page is live and offers its actions (RED L-3)', async () => {
    stubFetch([empty(204), json(200, BOLT)]);
    const u = userEvent.setup();
    show();
    await openAndConfirm(u);
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    await tick();
    expect(screen.queryByText(ARCHIVED_BANNER)).toBeNull();
    expect(deleteButton()).toBeInTheDocument();
    expect(field('Name')).toBeInTheDocument();
  });

  it('a stale refusal banner clears on the next answer: P1, press again, 403 → only the 403 sentence (R1-8)', async () => {
    stubFetch([refused('transaction.contention', CONTENTION), problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    show();
    await openAndConfirm(u);
    await waitFor(() => expect(dialog()).toHaveTextContent(CONTENTION));
    await u.click(confirm());
    await waitFor(() => expect(dialog()).toHaveTextContent('Delete and restore items'));
    expect(dialog()).not.toHaveTextContent(CONTENTION);
    expect(within(dialog()).getAllByText('Not deleted')).toHaveLength(1);
  });
});

describe('/items/[id] — Delete item: a close that is not a delete keeps the typed edits (M-5, final RED L-4)', () => {
  const editThenRefused = async (answer: Response | (() => Response), closeWith: 'Close' | 'Cancel' | 'Escape') => {
    const calls = stubFetch([answer, json(200, BOLT)]);
    const u = userEvent.setup();
    show();
    await u.type(field('SKU')!, 'Z');
    await openAndConfirm(u);
    await waitFor(() => expect(within(dialog()).getByText(/^(Not deleted|We couldn't reach the server\.)$/)).toBeInTheDocument());
    if (closeWith === 'Escape') await u.keyboard('{Escape}');
    else await u.click(within(dialog()).getByRole('button', { name: closeWith }));
    await waitFor(() => expect(calls.map((c) => c.method)).toEqual(['DELETE', 'GET']));
    await tick();
  };

  it('P1 → Cancel → the live re-read: the typed SKU is still in the field', async () => {
    await editThenRefused(refused('transaction.contention', CONTENTION), 'Cancel');
    expect(field('SKU')).toHaveValue('A-1Z');
  });

  it('403 → Cancel → the live re-read: the typed SKU is still in the field', async () => {
    await editThenRefused(problem(403, 'permission-denied'), 'Cancel');
    expect(field('SKU')).toHaveValue('A-1Z');
  });

  it('a lost connection → Escape → the live re-read: the typed SKU is still in the field', async () => {
    await editThenRefused(() => {
      throw new TypeError('Failed to fetch');
    }, 'Escape');
    expect(field('SKU')).toHaveValue('A-1Z');
  });

  it('I3 → Close → the re-read reads archived: the form is gone', async () => {
    stubFetch([refused('inventory_item.is_archived', I3), json(200, ARCHIVED)]);
    const u = userEvent.setup();
    show();
    await u.type(field('SKU')!, 'Z');
    await openAndConfirm(u);
    await u.click(await within(dialog()).findByRole('button', { name: 'Close' }));
    await waitFor(() => expect(screen.getByText(ARCHIVED_BANNER)).toBeInTheDocument());
    expect(field('SKU')).toBeNull();
  });

  it('a region error showing before the delete is still showing after P1 → Cancel (the live re-read changes nothing, L-4)', async () => {
    const calls = stubFetch([json(200, { ...BOLT, name: 'Boltx' }), json(503, {}), refused('transaction.contention', CONTENTION), json(200, { ...BOLT, name: 'Boltx' })]);
    const u = userEvent.setup();
    show();
    await u.type(field('Name')!, 'x');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    expect(await screen.findByText(REREAD_FAILED)).toBeInTheDocument();
    await openAndConfirm(u);
    await waitFor(() => expect(dialog()).toHaveTextContent(CONTENTION));
    await u.click(within(dialog()).getByRole('button', { name: 'Cancel' }));
    await waitFor(() => expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET', 'DELETE', 'GET']));
    await tick();
    expect(screen.getByText(REREAD_FAILED)).toBeInTheDocument();
  });
});

describe('/items/[id] — Delete item: the stamp rule (0a, carried to I-3)', () => {
  // The final RED's I-A / I-Afail (the warehouse W-A / W-Afail twins): a Save whose re-read is in flight when the item is
  // deleted asked for that reading before the delete, so it must not turn the page live again. The W-A twin also goes red
  // if the commit check becomes `<=` (the delete's own re-read carries the floor's stamp; i3-0a0b RED N4).
  const saveThenDelete = async (deleteReread: Response) => {
    const saveReread = held();
    const calls = stubFetch([json(200, { ...BOLT, name: 'Boltx' }), saveReread.reply, empty(204), deleteReread]);
    const u = userEvent.setup();
    show();
    await u.type(field('Name')!, 'x');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(calls).toHaveLength(2));
    await openAndConfirm(u);
    await waitFor(() => expect(region()).toHaveTextContent(AFTER));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await waitFor(() => expect(nav.refresh).toHaveBeenCalledTimes(1));
    saveReread.release(json(200, { ...BOLT, name: 'Boltx' }));
    await waitFor(() => expect(nav.refresh).toHaveBeenCalledTimes(2));
    await tick(20);
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET', 'DELETE', 'GET']);
  };

  it('an older Save re-read landing after a 204 does not turn the page live: archived banner stays, no Delete item, no form (I-A)', async () => {
    await saveThenDelete(json(200, { ...ARCHIVED, name: 'Boltx' }));
    expect(screen.getByText(ARCHIVED_BANNER)).toBeInTheDocument();
    expect(deleteButton()).toBeNull();
    expect(field('Name')).toBeNull();
  });

  it("…and the same when the delete's own re-read fails: no Delete item, no form, the region error shown (I-Afail, the 204 floor)", async () => {
    await saveThenDelete(json(503, {}));
    expect(deleteButton()).toBeNull();
    expect(field('Name')).toBeNull();
    expect(screen.getByText(REREAD_FAILED)).toBeInTheDocument();
  });

  // i3-0a0b RED H-1, item twins: a Save answering after the 204 was processed before the delete, so when its re-read
  // fails its 200 body must not turn the page live again.
  it('a Save answering after the 204, whose re-read fails, does not turn the page live (H-1)', async () => {
    const patch = held();
    const calls = stubFetch([patch.reply, empty(204), json(200, ARCHIVED), json(503, {})]);
    const u = userEvent.setup();
    show();
    await u.type(field('Name')!, 'x');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await openAndConfirm(u);
    await waitFor(() => expect(screen.getByText(ARCHIVED_BANNER)).toBeInTheDocument());
    patch.release(json(200, { ...BOLT, name: 'Boltx' }));
    await tick();
    await tick();
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'DELETE', 'GET', 'GET']);
    expect(screen.getByText(ARCHIVED_BANNER)).toBeInTheDocument();
    expect(deleteButton()).toBeNull();
    expect(field('Name')).toBeNull();
  });

  it("…and the same when the Save answers while the delete's re-read is in flight (H-1)", async () => {
    const patch = held();
    const reread = held();
    const calls = stubFetch([patch.reply, empty(204), reread.reply, json(503, {})]);
    const u = userEvent.setup();
    show();
    await u.type(field('Name')!, 'x');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await openAndConfirm(u);
    await waitFor(() => expect(calls).toHaveLength(3));
    patch.release(json(200, { ...BOLT, name: 'Boltx' }));
    await tick();
    reread.release(json(200, ARCHIVED));
    await tick();
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'DELETE', 'GET', 'GET']);
    expect(screen.getByText(ARCHIVED_BANNER)).toBeInTheDocument();
    expect(deleteButton()).toBeNull();
    expect(field('Name')).toBeNull();
  });

  // i3-0a0b RED L-2, item twin: Restore takes its stamp when it sends its re-read. A Retry pressed during the Restore
  // asked earlier, so its archived answer must not hide the restored item.
  it('a Retry pressed during a Restore and answering first does not hide the restored item (L-2)', async () => {
    const post = held();
    const retry = held();
    const reread = held();
    const calls = stubFetch([refused('inventory_item.is_not_archived', 'Not restored.'), json(503, {}), post.reply, retry.reply, reread.reply]);
    const u = userEvent.setup();
    show({ i: ARCHIVED });
    await u.click(screen.getByRole('button', { name: 'Restore item' }));
    const retryButton = await screen.findByRole('button', { name: 'Retry' });
    await u.click(screen.getByRole('button', { name: 'Restore item' }));
    await waitFor(() => expect(calls).toHaveLength(3));
    await u.click(retryButton);
    await waitFor(() => expect(calls).toHaveLength(4));
    post.release(json(200, BOLT));
    await waitFor(() => expect(calls).toHaveLength(5));
    retry.release(json(200, ARCHIVED));
    await tick();
    reread.release(json(200, BOLT));
    await tick();
    expect(screen.queryByText(ARCHIVED_BANNER)).toBeNull();
    expect(field('Name')).toBeInTheDocument();
  });

  // Close re-read vs Retry, both landing orders (d39635f): only a commit of NEWER evidence drops a page-owned re-read.
  const twoReads = async (first: 'retry' | 'close') => {
    const retry = held();
    const close = held();
    stubFetch([json(200, { ...BOLT, name: 'Boltx' }), json(503, {}), retry.reply, refused('inventory_item.is_archived', I3), close.reply]);
    const u = userEvent.setup();
    show();
    await u.type(field('Name')!, 'x');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await u.click(await screen.findByRole('button', { name: 'Retry' }));
    await openAndConfirm(u);
    await u.click(await within(dialog()).findByRole('button', { name: 'Close' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    const retryAnswers = () => retry.release(json(200, { ...BOLT, name: 'Boltx' }));
    const closeAnswers = () => close.release(json(200, { ...ARCHIVED, name: 'Boltx' }));
    for (const release of first === 'retry' ? [retryAnswers, closeAnswers] : [closeAnswers, retryAnswers]) {
      release();
      await tick();
    }
  };

  it('an older Retry that lands first does not drop the newer Close re-read: the page turns archived, focus on the h1', async () => {
    await twoReads('retry');
    expect(screen.getByText(ARCHIVED_BANNER)).toBeInTheDocument();
    expect(deleteButton()).toBeNull();
    expect(screen.getByRole('heading', { level: 1 })).toHaveFocus();
  });

  it('a newer Close re-read that lands first is not undone by the older Retry (reverse order)', async () => {
    await twoReads('close');
    expect(screen.getByText(ARCHIVED_BANNER)).toBeInTheDocument();
    expect(deleteButton()).toBeNull();
  });

  // Final RED M-3: an overtaken Close re-read is dropped (the warehouse X3 twin). A live answer would not be committed
  // anyway (M-5), so only the failure case can show the drop.
  it('an overtaken Close re-read that fails is dropped: no region error, the saved name stays (M-3)', async () => {
    const close = held();
    stubFetch([refused('transaction.contention', CONTENTION), close.reply, json(200, { ...BOLT, name: 'Bolty' }), json(200, { ...BOLT, name: 'Bolty' })]);
    const u = userEvent.setup();
    show();
    await openAndConfirm(u);
    await u.click(await within(dialog()).findByRole('button', { name: 'Cancel' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    const name = field('Name')!;
    await u.clear(name);
    await u.type(name, 'Bolty');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('A-1 — Bolty'));
    close.release(json(503, {}));
    await tick();
    expect(screen.queryByText(REREAD_FAILED)).toBeNull();
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('A-1 — Bolty');
  });

  // D-3: the caller's own stamp check also guards what follows a commit. An overtaken Close re-read that reads
  // archived is dropped, and must not move focus to the h1 or refresh the router cache.
  it('an overtaken Close re-read that reads archived is dropped: no focus jump, no extra refresh (D-3)', async () => {
    const close = held();
    stubFetch([refused('transaction.contention', CONTENTION), close.reply, json(200, { ...BOLT, name: 'Bolty' }), json(200, { ...BOLT, name: 'Bolty' })]);
    const u = userEvent.setup();
    show();
    await openAndConfirm(u);
    await u.click(await within(dialog()).findByRole('button', { name: 'Cancel' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    const name = field('Name')!;
    await u.clear(name);
    await u.type(name, 'Bolty');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('A-1 — Bolty'));
    const refreshes = nav.refresh.mock.calls.length;
    name.focus();
    close.release(json(200, ARCHIVED));
    await tick();
    expect(screen.queryByText(ARCHIVED_BANNER)).toBeNull();
    expect(nav.refresh.mock.calls.length).toBe(refreshes);
    expect(screen.getByRole('heading', { level: 1 })).not.toHaveFocus();
  });

  // i3-c1 fix-RED L-1: the SKU "After" speaks moves only with a commit that is not dropped.
  it('a dropped Save re-read (older than the 204) does not change the SKU "After" speaks', async () => {
    const patch = held();
    const del = held();
    const saveReread = held();
    const deleteReread = held();
    stubFetch([patch.reply, del.reply, saveReread.reply, deleteReread.reply]);
    const u = userEvent.setup();
    show();
    const sku = field('SKU')!;
    await u.clear(sku);
    await u.type(sku, 'A-2');
    await u.click(screen.getByRole('button', { name: 'Save' }));
    await openAndConfirm(u);
    patch.release(json(200, { ...BOLT, sku: 'A-2' }));
    await tick();
    del.release(empty(204));
    await tick();
    saveReread.release(json(200, { ...BOLT, sku: 'A-2' }));
    await tick();
    deleteReread.release(json(503, {}));
    await tick();
    await tick();
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('A-1 — Bolt');
    expect(region().textContent).toBe(AFTER);
  });
});

// FE-D1 order on the item delete, plain and under StrictMode (4.6 M-4; the warehouse probe): when the h1 takes focus the
// dialog is gone and the "After" sentence is not yet written.
for (const strict of [false, true]) {
  const wrap = (ui: ReactElement) => (strict ? <StrictMode>{ui}</StrictMode> : ui);
  describe(`Delete item — FE-D1 order${strict ? ' (StrictMode)' : ''}`, () => {
    it('204: the dialog closes, the h1 takes focus, THEN "After" is written; one refresh', async () => {
      stubFetch([empty(204), json(200, ARCHIVED)]);
      const u = userEvent.setup();
      renderWired(wrap(<ItemPage initial={BOLT} canEdit={false} canRestore canDelete />));
      await u.click(deleteButton()!);
      const atFocus: { text: boolean; dialog: boolean }[] = [];
      const onFocusIn = (e: FocusEvent) => {
        if ((e.target as HTMLElement).tagName === 'H1') atFocus.push({ text: (document.body.textContent ?? '').includes(AFTER), dialog: document.querySelector('[role="dialog"]') !== null });
      };
      document.addEventListener('focusin', onFocusIn);
      await u.click(confirm());
      await waitFor(() => expect(region()).toHaveTextContent(AFTER));
      document.removeEventListener('focusin', onFocusIn);
      expect(atFocus[0]).toEqual({ text: false, dialog: false });
      expect(screen.getByRole('heading', { level: 1 })).toHaveFocus();
      expect(nav.refresh).toHaveBeenCalledTimes(1);
    });
  });
}
