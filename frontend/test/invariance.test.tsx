import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import type { BulkRow } from '@/components/preview/fixtures/bulk';
import { BulkHarness } from './bulk/harness';


// Invariance tests (RED H-5 items 1–2). B-4 step 3 adds the CAP half (item 2), which replaces any literal-50 grep: the
// bar's cap is whatever `maxMembers` says, so a client that typed a cap anywhere makes the two runs disagree. The
// name/code half (item 1) arrives with the checklist and picker (steps 4–5).

const rows = (n: number): BulkRow[] =>
  Array.from({ length: n }, (_, i) => ({ id: `30000000-0000-4000-8000-${String(i + 1).padStart(12, '0')}`, name: `Row ${i + 1}`, code: `R${i + 1}` }));

async function selectAndRead(maxMembers: number, select: number) {
  render(<BulkHarness run={async () => ({ kind: 'unreachable' })} maxMembers={maxMembers} pages={[rows(8)]} />);
  const user = userEvent.setup();
  for (let i = 1; i <= select; i += 1) await user.click(screen.getByRole('checkbox', { name: `Select Row ${i}` }));
  const del = within(screen.getByRole('region', { name: 'Actions for the selected rows' })).getByRole('button', { name: 'Delete' });
  return { del, user };
}

describe('invariance — the bulk cap is the prop, nothing else (RED H-5 item 2)', () => {
  it('maxMembers 3: selecting 4 makes Delete aria-disabled with the reason built from 3', async () => {
    const { del, user } = await selectAndRead(3, 4);
    expect(del).toHaveAttribute('aria-disabled', 'true');
    expect(del).toHaveAccessibleDescription('You can delete up to 3 at a time.');
    await user.click(del);
    expect(screen.queryByRole('dialog')).toBeNull();
  });

  it('maxMembers 3: selecting 3 is allowed', async () => {
    const { del } = await selectAndRead(3, 3);
    expect(del).not.toHaveAttribute('aria-disabled');
  });

  it('maxMembers 7: selecting 7 is allowed, and Delete opens its dialog', async () => {
    const { del, user } = await selectAndRead(7, 7);
    expect(del).not.toHaveAttribute('aria-disabled');
    await user.click(del);
    expect(screen.getByRole('dialog')).toHaveAccessibleName('Delete 7 suppliers?');
  });

  it('maxMembers 7: selecting 8 is refused with the reason built from 7', async () => {
    const { del } = await selectAndRead(7, 8);
    expect(del).toHaveAccessibleDescription('You can delete up to 7 at a time.');
  });
});
