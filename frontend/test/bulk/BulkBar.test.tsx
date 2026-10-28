import { StrictMode } from 'react';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import type { BulkResult, BulkRunAction } from '@/lib/bulk/results';
import { COULD_NOT_REACH, REFUSED_WITHOUT_MESSAGE, WENT_WRONG } from '@/copy/headings';
import { BULK_CONTENTION, SUPPLIER_PAGES } from '@/components/preview/fixtures/bulk';
import { BulkHarness, counterMint } from './harness';

// B-4 step 3 behaviour, from the binding copy (b4-owed-copy.md Revision 2 > Revision 1: R1-3, R1-5, R1-7, R1-8, R1-9,
// R1-10; ux-copy §3.1 + Rev 3 items 1 and 4; R4-1; round-6 §2c) and architecture (Revision 3 A-5; C-1; RED M-6, M-8).

const PAGE1 = SUPPLIER_PAGES[0]!;
const CAP = 10;

function setup(answers: Array<BulkResult | Error>, opts: { maxMembers?: number; offered?: boolean; strict?: boolean } = {}) {
  const queue = [...answers];
  const run = vi.fn<BulkRunAction>(async () => {
    const next = queue.shift();
    if (next === undefined) throw new Error('no answer queued');
    if (next instanceof Error) throw next;
    return next;
  });
  const onReread = vi.fn();
  const ui = <BulkHarness run={run} maxMembers={opts.maxMembers ?? CAP} offered={opts.offered} onReread={onReread} mintKey={counterMint()} />;
  render(opts.strict ? <StrictMode>{ui}</StrictMode> : ui);
  return { run, onReread, user: userEvent.setup() };
}

const rowBox = (name: string) => screen.getByRole('checkbox', { name: `Select ${name}` });
const header = () => screen.getByRole('checkbox', { name: 'Select every row on this page' });
// While the modal dialog is open Radix hides the page (aria-hidden), and testing-library then computes no accessible
// name for it — so the bar is found by role + label only when the page is visible, else by its label attribute.
const bar = () =>
  screen.queryByRole('region', { name: 'Actions for the selected rows' }) ??
  document.querySelector<HTMLElement>('section[role="region"][aria-label="Actions for the selected rows"]');
const h1 = () => screen.getByRole('heading', { level: 1, name: 'Suppliers' });
const status = () => screen.getByRole('status', { hidden: true });
const dialog = () => screen.getByRole('dialog');

/**
 * The injected action has answered and its result is rendered: the sending button's `aria-busy` (set synchronously by
 * the click) is gone. Await this after every click that sends, before asserting on the result — the answer may land
 * in a later task than the click (b4-step3-progress.md "Flake").
 */
const settled = () => waitFor(() => expect(document.querySelector('[aria-busy="true"]')).toBeNull());


async function selectRows(user: ReturnType<typeof userEvent.setup>, n: number) {
  for (const r of PAGE1.slice(0, n)) await user.click(rowBox(r.name));
}
async function openDelete(user: ReturnType<typeof userEvent.setup>) {
  await user.click(within(bar()!).getByRole('button', { name: 'Delete' }));
}
async function confirmDelete(user: ReturnType<typeof userEvent.setup>) {
  await user.click(within(dialog()).getByRole('button', { name: 'Delete' }));
  await settled();
}

describe('bulk — the column and the header checkbox', () => {
  it('no column and no bar unless the caller offers bulk (delete permission + a view that admits it)', () => {
    setup([], { offered: false });
    expect(screen.queryAllByRole('checkbox')).toEqual([]);
    expect(bar()).toBeNull();
    expect(screen.getAllByRole('columnheader')).toHaveLength(1);
  });

  it('names: "Select every row on this page", "Select {first column}" (R4-1)', () => {
    setup([]);
    expect(header()).toBeInTheDocument();
    for (const r of PAGE1) expect(rowBox(r.name)).toBeInTheDocument();
  });

  it('header: mixed when some rows are selected; selects then unselects exactly this page', async () => {
    const { user } = setup([]);
    await user.click(rowBox(PAGE1[1]!.name));
    expect(header()).toBePartiallyChecked();
    await user.click(header());
    expect(header()).toBeChecked();
    expect(bar()).toHaveTextContent(`${PAGE1.length} selected`);
    await user.click(header());
    expect(header()).not.toBeChecked();
    expect(bar()).toBeNull();
  });

  it('a page change clears the selection; the header then selects only the new page (never beyond it)', async () => {
    const { user } = setup([]);
    await user.click(header());
    expect(bar()).toHaveTextContent('4 selected');
    await user.click(screen.getByRole('button', { name: 'Scope: live page 2' }));
    expect(bar()).toBeNull();
    expect(header()).not.toBeChecked();
    await user.click(header());
    expect(bar()).toHaveTextContent(`${SUPPLIER_PAGES[1]!.length} selected`);
    await user.click(screen.getByRole('button', { name: 'Scope: live page 1' }));
    expect(bar()).toBeNull();
    for (const r of PAGE1) expect(rowBox(r.name)).not.toBeChecked();
  });
});

describe('bulk — derived reset on a scope change (RED M-8)', () => {
  for (const strict of [false, true]) {
    it(`no render under the new scope ever shows the old selection${strict ? ' (StrictMode)' : ''}`, async () => {
      const seen: Array<[string, number]> = [];
      const ui = <BulkHarness run={async () => ({ kind: 'unreachable' })} maxMembers={CAP} onRender={(s, n) => seen.push([s, n])} />;
      render(strict ? <StrictMode>{ui}</StrictMode> : ui);
      const user = userEvent.setup();
      await user.click(header());
      await user.click(screen.getByRole('button', { name: 'Scope: archived' }));
      const underNew = seen.filter(([s]) => s === 'archived|0');
      expect(underNew.length).toBeGreaterThan(0);
      expect(underNew.map(([, n]) => n)).toEqual(underNew.map(() => 0));
      expect(seen.some(([s, n]) => s === 'live|0' && n === 4)).toBe(true);
    });
  }
});

describe('bulk — the bar', () => {
  it('"{n} selected" · Delete · Clear selection; Clear selection removes the bar and focuses the h1', async () => {
    const { user } = setup([]);
    await selectRows(user, 2);
    expect(bar()).toHaveTextContent('2 selected');
    expect(within(bar()!).getByRole('button', { name: 'Delete' })).toBeInTheDocument();
    expect(within(bar()!).queryByRole('button', { name: /archive/i })).toBeNull();
    await user.click(within(bar()!).getByRole('button', { name: 'Clear selection' }));
    expect(bar()).toBeNull();
    await waitFor(() => expect(h1()).toHaveFocus());
  });

  it('over the cap: Delete is aria-disabled with its visible reason and opens nothing', async () => {
    const { user, run } = setup([], { maxMembers: 3 });
    await selectRows(user, 4);
    const del = within(bar()!).getByRole('button', { name: 'Delete' });
    expect(del).toHaveAttribute('aria-disabled', 'true');
    expect(del).toHaveAccessibleDescription('You can delete up to 3 at a time.');
    expect(screen.getByText('You can delete up to 3 at a time.')).toBeVisible();
    await user.click(del);
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(run).not.toHaveBeenCalled();
  });

  it('archived view: the action is Restore, with its own cap reason', async () => {
    const { user } = setup([], { maxMembers: 2 });
    await user.click(screen.getByRole('button', { name: 'Scope: archived' }));
    await user.click(header());
    const restore = within(bar()!).getByRole('button', { name: 'Restore' });
    expect(restore).toHaveAccessibleDescription('You can restore up to 2 at a time.');
  });
});

describe('bulk — the Delete dialog (R1-10)', () => {
  it('names the count, says Delete, focuses Cancel; Esc closes and returns focus to the bar\'s Delete', async () => {
    const { user } = setup([]);
    await selectRows(user, 1);
    await openDelete(user);
    expect(dialog()).toHaveAccessibleName('Delete 1 supplier?');
    expect(dialog()).toHaveAccessibleDescription('Each one that can be deleted moves to the archived suppliers, where it can be restored later.');
    await waitFor(() => expect(within(dialog()).getByRole('button', { name: 'Cancel' })).toHaveFocus());
    expect(within(dialog()).queryByRole('button', { name: /archive/i })).toBeNull();
    await user.keyboard('{Escape}');
    expect(screen.queryByRole('dialog')).toBeNull();
    await waitFor(() => expect(within(bar()!).getByRole('button', { name: 'Delete' })).toHaveFocus());
  });

  it('plural title', async () => {
    const { user } = setup([]);
    await selectRows(user, 3);
    await openDelete(user);
    expect(dialog()).toHaveAccessibleName('Delete 3 suppliers?');
  });
});

describe('bulk — a run in flight keeps the Delete dialog open (b4-final-red M-1, spar R-2)', () => {
  it('no Cancel while pending; Escape and a pointer-down outside are ignored; unreachable → R1-1 banner, focused, Cancel back', async () => {
    let answer!: (r: BulkResult) => void;
    const run = vi.fn<BulkRunAction>(() => new Promise<BulkResult>((r) => (answer = r)));
    render(<BulkHarness run={run} maxMembers={CAP} mintKey={counterMint()} />);
    const user = userEvent.setup();
    await selectRows(user, 1);
    await openDelete(user);
    const d = dialog();
    const send = within(d).getByRole('button', { name: 'Delete' });
    await user.click(send);
    expect(send).toHaveAttribute('aria-busy', 'true');
    expect(within(d).queryByRole('button', { name: 'Cancel' })).toBeNull();
    await user.keyboard('{Escape}');
    fireEvent.pointerDown(document.body);
    expect(screen.getByRole('dialog')).toBe(d);
    expect(send).toHaveFocus();
    answer({ kind: 'unreachable' });
    await settled();
    expect(screen.getByRole('dialog')).toBe(d);
    await waitFor(() => expect(within(d).getByText(COULD_NOT_REACH)).toHaveFocus());
    expect(within(d).getByRole('button', { name: 'Cancel' })).toBeInTheDocument();
    expect(d.querySelector('[disabled]')).toBeNull();
    expect(bar()).toHaveTextContent('1 selected');
  });

  it('control: with nothing in flight, a pointer-down outside closes it', async () => {
    const { user } = setup([]);
    await selectRows(user, 1);
    await openDelete(user);
    fireEvent.pointerDown(document.body);
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
  });
});

describe('bulk — a finished run (R1-9: cleared; A-5: the caller re-reads)', () => {
  it('all applied: counts only, selection cleared, bar gone, focus on the h1, one re-read, ids + key sent', async () => {
    const { user, run, onReread } = setup([{ kind: 'done', requested: 2, applied: 2 }]);
    await selectRows(user, 2);
    await openDelete(user);
    await confirmDelete(user);
    expect(run).toHaveBeenCalledTimes(1);
    expect(run).toHaveBeenCalledWith([PAGE1[0]!.id, PAGE1[1]!.id], 'key-1');
    expect(status()).toHaveTextContent('2 of 2 deleted.');
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(bar()).toBeNull();
    await waitFor(() => expect(h1()).toHaveFocus());
    expect(onReread).toHaveBeenCalledTimes(1);
    for (const r of PAGE1) expect(rowBox(r.name)).not.toBeChecked();
  });

  it('some applied: the remainder sentence; Dismiss message empties the region and focuses the h1', async () => {
    const { user } = setup([{ kind: 'done', requested: 4, applied: 3 }]);
    await selectRows(user, 4);
    await openDelete(user);
    await confirmDelete(user);
    expect(status()).toHaveTextContent(
      "3 of 4 deleted. The other 1 weren't changed. A record may already be archived, no longer exist, or still have open work.",
    );
    await user.click(screen.getByRole('button', { name: 'Dismiss message' }));
    expect(status()).toHaveTextContent('');
    await waitFor(() => expect(h1()).toHaveFocus());
  });

  it('restore acts at once (no dialog) and its remainder names the restore reasons', async () => {
    const { user, run, onReread } = setup([{ kind: 'done', requested: 3, applied: 1 }]);
    await user.click(screen.getByRole('button', { name: 'Scope: archived' }));
    await user.click(header());
    await user.click(within(bar()!).getByRole('button', { name: 'Restore' }));
    await settled();
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(run).toHaveBeenCalledTimes(1);
    expect(status()).toHaveTextContent(
      "1 of 3 restored. The other 2 weren't changed. A record may no longer be archived or exist, another record may now use its code or name, or a record it depends on may be archived.",
    );
    expect(bar()).toBeNull();
    await waitFor(() => expect(h1()).toHaveFocus());
    expect(onReread).toHaveBeenCalledTimes(1);
  });
});

describe('bulk — the key (Revision 1 C-1)', () => {
  it('a second run of an unchanged selection passes the same key', async () => {
    const { user, run } = setup([{ kind: 'unreachable' }, { kind: 'done', requested: 2, applied: 2 }]);
    await selectRows(user, 2);
    await openDelete(user);
    await confirmDelete(user);
    await confirmDelete(user);
    expect(run.mock.calls.map((c) => c[1])).toEqual(['key-1', 'key-1']);
  });

  it('the same key after closing and reopening the dialog on the unchanged selection', async () => {
    const { user, run } = setup([{ kind: 'server-error' }, BULK_CONTENTION]);
    await selectRows(user, 2);
    await openDelete(user);
    await confirmDelete(user);
    await user.click(within(dialog()).getByRole('button', { name: 'Cancel' }));
    await openDelete(user);
    await confirmDelete(user);
    expect(run.mock.calls.map((c) => c[1])).toEqual(['key-1', 'key-1']);
  });

  it('any change to the selection mints a new key', async () => {
    const { user, run } = setup([{ kind: 'unreachable' }, { kind: 'unreachable' }, { kind: 'unreachable' }]);
    await selectRows(user, 2);
    await openDelete(user);
    await confirmDelete(user);
    await user.click(within(dialog()).getByRole('button', { name: 'Cancel' }));
    await user.click(rowBox(PAGE1[2]!.name)); // add one
    await openDelete(user);
    await confirmDelete(user);
    await user.click(within(dialog()).getByRole('button', { name: 'Cancel' }));
    await user.click(rowBox(PAGE1[2]!.name)); // and take it out again: a change, even back to the old set
    await openDelete(user);
    await confirmDelete(user);
    expect(run.mock.calls.map((c) => c[1])).toEqual(['key-1', 'key-2', 'key-3']);
  });
});

describe('bulk — the after-message speaks for every run (b4-final-red M-3)', () => {
  it('two identical runs: the region\'s text changes for the second one too', async () => {
    const { user } = setup([
      { kind: 'done', requested: 1, applied: 1 },
      { kind: 'done', requested: 1, applied: 1 },
    ]);
    await selectRows(user, 1);
    await openDelete(user);
    await confirmDelete(user);
    expect(status()).toHaveTextContent('1 of 1 deleted.');
    const texts: string[] = [];
    const observer = new MutationObserver(() => texts.push(status().textContent ?? ''));
    observer.observe(status(), { childList: true, characterData: true, subtree: true });
    await selectRows(user, 1);
    await openDelete(user);
    await confirmDelete(user);
    await waitFor(() => expect(status()).toHaveTextContent('1 of 1 deleted.'));
    observer.disconnect();
    expect(texts.length).toBeGreaterThan(0);
    expect(texts.at(-1)).toBe('1 of 1 deleted.');
  });
});

describe('bulk — refusals and interruptions keep the selection and its key (R1-9)', () => {
  it('bulk.contention: the server sentence inside the dialog under Not deleted, heading focused, dialog open', async () => {
    const { user } = setup([BULK_CONTENTION]);
    await selectRows(user, 2);
    await openDelete(user);
    await confirmDelete(user);
    const heading = within(dialog()).getByText('Not deleted');
    await waitFor(() => expect(heading).toHaveFocus());
    expect(within(dialog()).getByText(BULK_CONTENTION.message)).toBeInTheDocument();
    expect(within(dialog()).queryByRole('alert')).toBeNull();
    expect(bar()).toHaveTextContent('2 selected');
    expect(status()).toHaveTextContent('');
  });

  it('lost connection, 5xx and a thrown action: R1-1 heading, R1-3 body', async () => {
    const { user } = setup([{ kind: 'unreachable' }, { kind: 'server-error' }, new Error('boom')]);
    await selectRows(user, 2);
    await openDelete(user);
    const body = "We can't tell whether these suppliers were deleted. Press Delete to try again: if they were, you'll see the result.";
    await confirmDelete(user);
    await waitFor(() => expect(within(dialog()).getByText(COULD_NOT_REACH)).toHaveFocus());
    expect(within(dialog()).getByText(body)).toBeInTheDocument();
    await confirmDelete(user);
    await waitFor(() => expect(within(dialog()).getByText(WENT_WRONG)).toHaveFocus());
    await confirmDelete(user);
    await waitFor(() => expect(within(dialog()).getByText(COULD_NOT_REACH)).toHaveFocus());
    expect(bar()).toHaveTextContent('2 selected');
  });

  it('a lost permission (403): Not deleted with R1-5\'s bulk body', async () => {
    const { user } = setup([{ kind: 'forbidden' }]);
    await selectRows(user, 1);
    await openDelete(user);
    await confirmDelete(user);
    await waitFor(() => expect(within(dialog()).getByText('Not deleted')).toHaveFocus());
    expect(within(dialog()).getByText('You no longer have permission to delete and restore suppliers. Your workspace administrator can grant it.')).toBeInTheDocument();
    expect(bar()).toHaveTextContent('1 selected');
  });

  it('a refusal with no message, and a 404 (R1-7 step 5; R1-6 last line)', async () => {
    const { user } = setup([{ kind: 'refused', rule: 'idempotency-key-conflict', message: '' }, { kind: 'not-found' }]);
    await selectRows(user, 1);
    await openDelete(user);
    await confirmDelete(user);
    expect(within(dialog()).getByText(REFUSED_WITHOUT_MESSAGE)).toBeInTheDocument();
    await confirmDelete(user);
    expect(within(dialog()).getByText("We couldn't find that. It may have been deleted, or you may not have access.")).toBeInTheDocument();
  });

  it('a 401 changes nothing on the page (R1-4: the fetch wrapper navigates)', async () => {
    const { user } = setup([{ kind: 'session-ended' }]);
    await selectRows(user, 1);
    await openDelete(user);
    await confirmDelete(user);
    expect(dialog()).toBeInTheDocument();
    expect(within(dialog()).queryByText('Not deleted')).toBeNull();
    expect(bar()).toHaveTextContent('1 selected');
  });

  it('restore: refusals show in the banner zone, heading focused, no live role; selection and key kept', async () => {
    const { user, run } = setup([BULK_CONTENTION, { kind: 'unreachable' }]);
    await user.click(screen.getByRole('button', { name: 'Scope: archived' }));
    await user.click(header());
    await user.click(within(bar()!).getByRole('button', { name: 'Restore' }));
    await settled();
    await waitFor(() => expect(screen.getByText('Not restored')).toHaveFocus());
    expect(screen.getByText(BULK_CONTENTION.message)).toBeInTheDocument();
    expect(screen.queryByRole('alert')).toBeNull();
    await user.click(within(bar()!).getByRole('button', { name: 'Restore' }));
    await settled();
    await waitFor(() => expect(screen.getByText(COULD_NOT_REACH)).toHaveFocus());
    expect(screen.getByText("We can't tell whether these suppliers were restored. Press Restore to try again: if they were, you'll see the result.")).toBeInTheDocument();
    expect(run.mock.calls.map((c) => c[1])).toEqual(['key-1', 'key-1']);
    expect(bar()).toHaveTextContent('3 selected');
  });
});

describe('bulk — key past 24 h (R1-8)', () => {
  it('Already sent: the selection is cleared, the bar goes, focus on the h1, the banner is an alert, one re-read', async () => {
    const { user, onReread } = setup([{ kind: 'key-expired' }]);
    await selectRows(user, 2);
    await openDelete(user);
    await confirmDelete(user);
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(bar()).toBeNull();
    await waitFor(() => expect(h1()).toHaveFocus());
    const alert = screen.getByRole('alert');
    expect(alert).toHaveTextContent('Already sent');
    expect(alert).toHaveTextContent("These were sent more than a day ago, so we can't show that result again. The list now shows where they are.");
    expect(onReread).toHaveBeenCalledTimes(1);
    expect(status()).toHaveTextContent('');
  });

  it('pressing again needs a new selection: no re-run of the old key on the same selection', async () => {
    const { user, run } = setup([{ kind: 'key-expired' }, { kind: 'done', requested: 2, applied: 2 }]);
    await selectRows(user, 2);
    await openDelete(user);
    await confirmDelete(user);
    expect(run).toHaveBeenCalledTimes(1);
    await selectRows(user, 2);
    expect(screen.queryByRole('alert')).toBeNull(); // the banner clears on the next selection change
    await openDelete(user);
    await confirmDelete(user);
    expect(run.mock.calls.map((c) => c[1])).toEqual(['key-1', 'key-2']);
  });
});

describe('bulk — no server sentence in client code', () => {
  it('no server sentence is stored in the bulk copy or components', async () => {
    const { readdirSync, readFileSync } = await import('node:fs');
    const path = await import('node:path');
    const root = path.resolve(__dirname, '../../src');
    const files = ['copy/bulk.ts', ...readdirSync(path.join(root, 'components/bulk')).map((f) => `components/bulk/${f}`)];
    for (const f of files) expect(readFileSync(path.join(root, f), 'utf8'), f).not.toContain('being changed at the same moment');
  });
});

