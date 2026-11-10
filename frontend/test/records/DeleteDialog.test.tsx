import { useRef } from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { DeleteDialog, type DeleteDialogCopy } from '@/components/records/DeleteDialog';
import type { DeleteResult } from '@/lib/results';

// The shared Delete dialog, props only (Revision 8 R8-3; items I-0a RED M-1). Every copy field is a distinct sentinel,
// so a branch that renders the wrong field, or a caller-built copy object that swaps two, cannot pass. No family copy is
// imported: the dialog must render exactly what it was given.

const COPY: DeleteDialogCopy = {
  title: 'TITLE',
  body: 'BODY',
  confirm: 'CONFIRM',
  cancel: 'CANCEL',
  close: 'CLOSE',
  interrupted: 'INTERRUPTED',
  forbidden: { before: 'F-BEFORE ', after: ' F-AFTER' },
};

/** A real permission code with a served label (copy/permissionLabels.ts), read by B-8. */
const CODE = 'warehouse.delete';

function Harness({ run, onClose = () => {} }: { run: () => Promise<DeleteResult>; onClose?: (o: { deleted: boolean; reread: boolean }) => void }) {
  const h1 = useRef<HTMLHeadingElement>(null);
  return (
    <>
      <h1 ref={h1} tabIndex={-1}>
        Page
      </h1>
      <DeleteDialog copy={COPY} run={run} deleteCode={CODE} onClose={onClose} fallbackFocusRef={h1} />
    </>
  );
}

const dialog = () => screen.getByRole('dialog');
const once = (r: DeleteResult) => async () => r;

async function press(run: () => Promise<DeleteResult>) {
  const u = userEvent.setup();
  render(<Harness run={run} />);
  await u.click(within(dialog()).getByRole('button', { name: 'CONFIRM' }));
  return u;
}

describe('components/records/DeleteDialog — focus (B-7, ST-9b: danger dialog, initial focus Cancel)', () => {
  // Pinned here as well as in test/wired/warehouse-delete.test.tsx: a dialog that opened on Delete would let one stray
  // Enter delete the record. Removing Cancel's initial focus went red only in the wired test (items I-0a plant).
  it('on open, focus is on Cancel, not on Delete', async () => {
    render(<Harness run={once({ kind: 'deleted' })} />);
    await waitFor(() => expect(within(dialog()).getByRole('button', { name: 'CANCEL' })).toHaveFocus());
    expect(within(dialog()).getByRole('button', { name: 'CONFIRM' })).not.toHaveFocus();
  });

  it.each([
    ['a refusal', { kind: 'refused', rule: 'x.planted', message: 'X-planted refusal' }],
    ['contention', { kind: 'contention', message: 'X-planted try again' }],
  ] as const)('after %s, the "Not deleted" heading takes focus', async (_name, r) => {
    await press(once(r));
    await waitFor(() => expect(within(dialog()).getByText('Not deleted')).toHaveFocus());
    // waitFor returns the first moment focus arrives; one task later it must still be there (nothing takes it back).
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(within(dialog()).getByText('Not deleted')).toHaveFocus();
  });

  it('a second contention moves focus back to the "Not deleted" heading', async () => {
    // Contention keeps Delete (B-7 "Exception"): pressing it again puts focus on Delete, and the second answer must take
    // focus back to the heading, or a screen-reader user gets no cue that it was refused again.
    const u = await press(once({ kind: 'contention', message: 'X-planted try again' }));
    await waitFor(() => expect(within(dialog()).getByText('Not deleted')).toHaveFocus());
    await u.click(within(dialog()).getByRole('button', { name: 'CONFIRM' }));
    await waitFor(() => expect(within(dialog()).getByText('Not deleted')).toHaveFocus());
  });
});

describe('components/records/DeleteDialog — each branch renders its own copy field (RED M-1)', () => {
  it('renders title, body, cancel and confirm from props', () => {
    render(<Harness run={once({ kind: 'deleted' })} />);
    expect(dialog()).toHaveTextContent('TITLE');
    expect(dialog()).toHaveTextContent('BODY');
    expect(within(dialog()).getByRole('button', { name: 'CANCEL' })).toBeInTheDocument();
    expect(within(dialog()).getByRole('button', { name: 'CONFIRM' })).toBeInTheDocument();
  });

  it('a 404: "We couldn\u2019t find that." under "Not deleted", then Close only (0b; ruling 1(b))', async () => {
    await press(once({ kind: 'not-found' }));
    await waitFor(() => expect(dialog()).toHaveTextContent("We couldn't find that."));
    expect(dialog()).toHaveTextContent('Not deleted');
    expect(within(dialog()).queryByRole('button', { name: 'CONFIRM' })).toBeNull();
    expect(within(dialog()).getByRole('button', { name: 'CLOSE' })).toBeInTheDocument();
    expect(within(dialog()).queryByRole('button', { name: 'CANCEL' })).toBeNull();
  });

  // Ruling 1(b), second half: a 403 keeps Delete (and Cancel); only a 404 leaves Close only.
  it('a 403 is before + the permission label in <em> + after, as one sentence; Delete stays', async () => {
    await press(once({ kind: 'forbidden' }));
    await waitFor(() => expect(dialog()).toHaveTextContent('F-BEFORE Delete and restore warehouses F-AFTER'));
    const em = within(dialog()).getByText('Delete and restore warehouses');
    expect(em.tagName).toBe('EM');
    expect(em.parentElement).toHaveTextContent(/^F-BEFORE Delete and restore warehouses F-AFTER$/);
    expect(within(dialog()).getByRole('button', { name: 'CONFIRM' })).toBeInTheDocument();
    expect(within(dialog()).getByRole('button', { name: 'CANCEL' })).toBeInTheDocument();
    expect(within(dialog()).queryByRole('button', { name: 'CLOSE' })).toBeNull();
  });

  it.each([
    ['unreachable', "We couldn't reach the server."],
    ['server-error', 'Something went wrong on our side.'],
  ] as const)('%s: the interrupted field under its heading; Delete stays', async (kind, heading) => {
    await press(once({ kind }));
    await waitFor(() => expect(dialog()).toHaveTextContent('INTERRUPTED'));
    expect(dialog()).toHaveTextContent(heading);
    expect(within(dialog()).getByRole('button', { name: 'CONFIRM' })).toBeInTheDocument();
  });

  it('contention: the sentence as sent, Delete stays, Cancel stays', async () => {
    await press(once({ kind: 'contention', message: 'X-planted try again' }));
    await waitFor(() => expect(dialog()).toHaveTextContent('X-planted try again'));
    expect(within(dialog()).getByRole('button', { name: 'CONFIRM' })).toBeInTheDocument();
    expect(within(dialog()).getByRole('button', { name: 'CANCEL' })).toBeInTheDocument();
  });

  it('any other refusal: the sentence as sent, then Close only', async () => {
    await press(once({ kind: 'refused', rule: 'x.planted', message: 'X-planted refusal' }));
    await waitFor(() => expect(dialog()).toHaveTextContent('X-planted refusal'));
    expect(within(dialog()).queryByRole('button', { name: 'CONFIRM' })).toBeNull();
    expect(within(dialog()).getByRole('button', { name: 'CLOSE' })).toBeInTheDocument();
    expect(within(dialog()).queryByRole('button', { name: 'CANCEL' })).toBeNull();
  });

  it('deleted: onClose once with deleted and reread', async () => {
    const closes: { deleted: boolean; reread: boolean }[] = [];
    const u = userEvent.setup();
    render(<Harness run={once({ kind: 'deleted' })} onClose={(o) => closes.push(o)} />);
    await u.click(within(dialog()).getByRole('button', { name: 'CONFIRM' }));
    await waitFor(() => expect(closes).toEqual([{ deleted: true, reread: true }]));
  });
});

// Items I-3 T-2 (items-i3-design.md Revision 1 — FINAL §1.3 T-2, final RED M-3): the optional warning line. Absent or ''
// → the description is exactly the body (the warehouse and location dialogs are unchanged). Present → its own paragraph
// FIRST in the description, icon aria-hidden, no heading, no role. A refusal renders after the description (the shared
// Dialog order); the warning and the body stay in it, warning first.
describe('components/records/DeleteDialog — the warning line (items I-3, T-2)', () => {
  function WarningHarness({ warning, run = once({ kind: 'deleted' }) }: { warning?: string; run?: () => Promise<DeleteResult> }) {
    const h1 = useRef<HTMLHeadingElement>(null);
    return (
      <>
        <h1 ref={h1} tabIndex={-1}>
          Page
        </h1>
        <DeleteDialog copy={{ ...COPY, warning }} run={run} deleteCode={CODE} onClose={() => {}} fallbackFocusRef={h1} />
      </>
    );
  }
  const description = () => document.getElementById(dialog().getAttribute('aria-describedby')!)!;

  it.each([['absent', undefined], ["''", '']] as const)('warning %s: the description is exactly the body, one paragraph', (_, warning) => {
    render(<WarningHarness warning={warning} />);
    expect(description().textContent).toBe('BODY');
    expect(description().querySelectorAll('p')).toHaveLength(1);
    expect(description().querySelector('svg')).toBeNull();
  });

  it('warning present: its own paragraph first, then the body; icon aria-hidden; no heading, no role', () => {
    render(<WarningHarness warning="WARNING" />);
    const ps = [...description().querySelectorAll('p')];
    expect(ps.map((p) => p.textContent)).toEqual(['WARNING', 'BODY']);
    expect(description().textContent!.startsWith('WARNING')).toBe(true);
    expect(ps[0]!.querySelector('svg')!.getAttribute('aria-hidden')).toBe('true');
    expect(within(description()).queryByRole('heading')).toBeNull();
    expect(ps[0]!.closest('[role]')).toBe(dialog());
    expect(ps[0]!.hasAttribute('role')).toBe(false);
  });

  it('a refusal renders after the description; the warning and the body stay in it, warning first', async () => {
    const u = userEvent.setup();
    render(<WarningHarness warning="WARNING" run={once({ kind: 'contention', message: 'X-planted try again' })} />);
    await u.click(within(dialog()).getByRole('button', { name: 'CONFIRM' }));
    const refusal = await within(dialog()).findByText('X-planted try again');
    expect([...description().querySelectorAll('p')].map((p) => p.textContent)).toEqual(['WARNING', 'BODY']);
    expect(description().compareDocumentPosition(refusal) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(description().contains(refusal)).toBe(false);
  });
});
