import { useRef } from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { RestoreActions, type RestoreActionsCopy } from '@/components/records/RestoreActions';
import { TooltipProvider } from '@/components/ui/Tooltip';
import type { RestoreResult } from '@/lib/results';

// The shared archived banner and Restore action, props only (Revision 8 R8-3; items I-0a RED M-1). Every copy field is a
// distinct sentinel and the payload is synthetic, so a branch that renders the wrong field, or reads the wrong part of the
// payload, cannot pass. No family copy or type is imported.

interface Payload {
  thing: { code: string };
}

const COPY: RestoreActionsCopy<Payload> = {
  archivedBefore: 'ARCH-B ',
  archivedAfter: ' ARCH-A',
  action: 'ACTION',
  after: (r) => `AFTER:${r.thing.code}`,
  interrupted: 'INTERRUPTED',
  forbidden: { before: 'F-BEFORE ', after: ' F-AFTER' },
};

const CODE = 'warehouse.delete';

function Harness({ restore, spoken }: { restore: () => Promise<RestoreResult<Payload>>; spoken: string[] }) {
  const h1 = useRef<HTMLHeadingElement>(null);
  return (
    <TooltipProvider>
      <h1 ref={h1} tabIndex={-1}>
        Page
      </h1>
      <RestoreActions archivedAt="2026-09-02T10:00:00Z" copy={COPY} canRestore restore={restore} restoreCode={CODE} timeZone="UTC" announce={(m) => spoken.push(m)} fallbackFocusRef={h1} />
    </TooltipProvider>
  );
}

async function press(r: RestoreResult<Payload>) {
  const spoken: string[] = [];
  const u = userEvent.setup();
  render(<Harness restore={async () => r} spoken={spoken} />);
  await u.click(screen.getByRole('button', { name: 'ACTION' }));
  return spoken;
}

describe('components/records/RestoreActions — each branch renders its own copy field (RED M-1)', () => {
  it('the banner is archivedBefore + date + archivedAfter; the button is action', () => {
    render(<Harness restore={async () => ({ kind: 'unreachable' })} spoken={[]} />);
    expect(screen.getByText(/ARCH-B/)).toHaveTextContent(/^ARCH-B .*2026.* ARCH-A$/);
    expect(screen.getByRole('button', { name: 'ACTION' })).toBeInTheDocument();
  });

  it('restored: speaks after(payload) and moves focus to the heading', async () => {
    const spoken = await press({ kind: 'restored', thing: { code: 'K1' } });
    await waitFor(() => expect(spoken).toEqual(['AFTER:K1']));
    expect(screen.getByRole('heading', { name: 'Page' })).toHaveFocus();
  });

  it('already-live with a sentence: speaks the sentence as sent', async () => {
    const spoken = await press({ kind: 'already-live', thing: { code: 'K2' }, message: 'X-planted it is live' });
    await waitFor(() => expect(spoken).toEqual(['X-planted it is live']));
  });

  it("already-live with an empty sentence: speaks after(payload), never ''", async () => {
    const spoken = await press({ kind: 'already-live', thing: { code: 'K3' }, message: '' });
    await waitFor(() => expect(spoken).toEqual(['AFTER:K3']));
  });

  it('a 403 is before + the permission label in <em> + after, as one sentence, under Not restored', async () => {
    await press({ kind: 'forbidden' });
    const em = await screen.findByText('Delete and restore warehouses');
    expect(em.tagName).toBe('EM');
    expect(em.parentElement).toHaveTextContent(/^F-BEFORE Delete and restore warehouses F-AFTER$/);
    expect(screen.getByText('Not restored')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'ACTION' })).toBeInTheDocument();
  });

  it.each([
    ['unreachable', "We couldn't reach the server."],
    ['server-error', 'Something went wrong on our side.'],
  ] as const)('%s: the interrupted field under its heading; the button stays', async (kind, heading) => {
    const spoken = await press({ kind });
    expect(await screen.findByText('INTERRUPTED')).toBeInTheDocument();
    expect(screen.getByText(heading, { exact: false })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'ACTION' })).toBeInTheDocument();
    expect(spoken).toEqual([]);
  });
});
