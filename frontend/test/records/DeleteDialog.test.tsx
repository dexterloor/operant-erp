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

describe('components/records/DeleteDialog — each branch renders its own copy field (RED M-1)', () => {
  it('renders title, body, cancel and confirm from props', () => {
    render(<Harness run={once({ kind: 'deleted' })} />);
    expect(dialog()).toHaveTextContent('TITLE');
    expect(dialog()).toHaveTextContent('BODY');
    expect(within(dialog()).getByRole('button', { name: 'CANCEL' })).toBeInTheDocument();
    expect(within(dialog()).getByRole('button', { name: 'CONFIRM' })).toBeInTheDocument();
  });

  it('a 403 is before + the permission label in <em> + after, as one sentence; Delete stays', async () => {
    await press(once({ kind: 'forbidden' }));
    await waitFor(() => expect(dialog()).toHaveTextContent('F-BEFORE Delete and restore warehouses F-AFTER'));
    const em = within(dialog()).getByText('Delete and restore warehouses');
    expect(em.tagName).toBe('EM');
    expect(em.parentElement).toHaveTextContent(/^F-BEFORE Delete and restore warehouses F-AFTER$/);
    expect(within(dialog()).getByRole('button', { name: 'CONFIRM' })).toBeInTheDocument();
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
