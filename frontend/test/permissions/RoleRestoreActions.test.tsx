import { useRef, useState } from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { RoleRestoreActions } from '@/components/permissions/RoleRestoreActions';
import { TooltipProvider } from '@/components/ui/Tooltip';
import type { Role } from '@/lib/api/roles';
import type { RestoreRoleAction, RestoreRoleResult } from '@/lib/permissions/results';
import { CUSTOM_ROLE } from '@/components/preview/fixtures/permissions';

// Restore role, props only (restore-role-design.md Revision 2 F-2 T-6, T-7, T-14; F-5 M-1, M-5). A refusal's body is the
// server's sentence rendered as sent: these tests quote PLANTED sentences only, never a shipped or planned server
// sentence, so a client copy table for any refusal code cannot pass them. Copy is asserted as literal text.

const DELETED: Role = { ...CUSTOM_ROLE, archived_at: '2026-09-02T10:00:00Z' };

function Harness({ restoreRole }: { restoreRole: RestoreRoleAction }) {
  const h1 = useRef<HTMLHeadingElement>(null);
  const [role] = useState(DELETED);
  return (
    <TooltipProvider>
      <h1 ref={h1} tabIndex={-1}>
        {role.name}
      </h1>
      <RoleRestoreActions role={role} canRestore restoreRole={restoreRole} fallbackFocusRef={h1} timeZone="UTC" />
    </TooltipProvider>
  );
}

const refusedWith = (message: string): RestoreRoleAction => async () => ({ kind: 'refused', rule: 'role.x_planted', message });

describe('RoleRestoreActions — a refusal is the server sentence, verbatim (T-6, T-7)', () => {
  const LONG = `X-planted long sentence: ${'every word of it stays exactly as the server wrote it, '.repeat(6)}and it ends here.`;
  it.each([
    ['plain', 'X-planted sentence'],
    ['punctuation, no %', "X-planted: it's (really) refused; \"quoted\" — and 100 percent sure?"],
    ['long', LONG],
  ])('%s: under Not restored, heading focused, no live role on the banner, the button stays', async (_label, message) => {
    const user = userEvent.setup();
    render(<Harness restoreRole={refusedWith(message)} />);
    await user.click(screen.getByRole('button', { name: 'Restore role' }));
    const heading = await screen.findByText('Not restored');
    expect(screen.getByText(message)).toBeInTheDocument();
    expect(screen.getByText(message).tagName).toBe('P');
    await waitFor(() => expect(heading).toHaveFocus());
    const banner = heading.closest('div')!.parentElement!;
    expect(banner).not.toHaveAttribute('role');
    expect(banner).not.toHaveAttribute('aria-live');
    expect(banner.closest('[role="status"], [role="alert"], [aria-live]')).toBeNull();
    expect(screen.getByRole('button', { name: 'Restore role' })).toBeInTheDocument();
  });

  it('an empty message → the no-message body', async () => {
    const user = userEvent.setup();
    render(<Harness restoreRole={refusedWith('')} />);
    await user.click(screen.getByRole('button', { name: 'Restore role' }));
    expect(await screen.findByText('Not restored')).toBeInTheDocument();
    expect(screen.getByText("This change wasn't made. Reload the page to see where things stand, then try again.")).toBeInTheDocument();
  });

  it('a thrown action → the unreachable heading + interrupted body, and the button is not left busy (L-6)', async () => {
    const user = userEvent.setup();
    render(
      <Harness
        restoreRole={async () => {
          throw new Error('boom');
        }}
      />,
    );
    await user.click(screen.getByRole('button', { name: 'Restore role' }));
    expect(await screen.findByText("We couldn't reach the server.")).toBeInTheDocument();
    expect(screen.getByText("We can't tell whether Sample role was restored. Press Restore role to try again.")).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Restore role' })).not.toHaveAttribute('aria-busy');
  });
});

describe('RoleRestoreActions — double press (T-14)', () => {
  it('a held answer + two clicks → one request; aria-busy while held', async () => {
    let calls = 0;
    let release: (r: RestoreRoleResult) => void = () => {};
    const restoreRole: RestoreRoleAction = () => {
      calls += 1;
      return new Promise<RestoreRoleResult>((resolve) => {
        release = resolve;
      });
    };
    const user = userEvent.setup();
    render(<Harness restoreRole={restoreRole} />);
    const button = screen.getByRole('button', { name: 'Restore role' });
    await user.click(button);
    await user.click(button);
    // The count first, so a run tells the two halves apart (F-5 M-1, build step 3a): with the guard alone the count holds
    // and only aria-busy is lost; with both gone the second click sends a second request.
    expect(calls).toBe(1);
    expect(screen.getByRole('button', { name: 'Restore role' })).toHaveAttribute('aria-busy', 'true');
    release({ kind: 'refused', rule: 'role.x_planted', message: 'X-planted sentence' });
    expect(await screen.findByText('X-planted sentence')).toBeInTheDocument();
    expect(calls).toBe(1);
  });
});
