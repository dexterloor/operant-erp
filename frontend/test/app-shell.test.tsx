import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AppShell } from '@/app/(app)/AppShell';
import type { SignOutResult } from '@/lib/bff/authCalls';

// b3b-followup C-5c (design §6.8, L-11): a signed-in page brought back by Back from the browser's back-forward cache is
// reloaded, so it never shows what an earlier session (signed out since, or replaced) left on screen. SEC to confirm.
vi.mock('next/navigation', () => ({ usePathname: () => '/admin/users' }));

// C-8: the sign-out call and the document navigation are stubbed; only the shell's handling of the answer is under test.
const io = vi.hoisted(() => ({ signOut: vi.fn(), assign: vi.fn() }));
vi.mock('@/lib/bff/authCalls', () => ({ signOut: io.signOut }));
vi.mock('@/lib/auth/results', async (original) => ({ ...(await original<typeof import('@/lib/auth/results')>()), assignLocation: io.assign }));

const viewer = { displayName: 'Sample Person', email: 'person@example.com', permissionCodes: ['app_user.view'] };
const show = (reload: () => void) => render(<AppShell viewer={viewer} workspaceSignInName="acme" reload={reload}><p>page</p></AppShell>);
const pageshow = (persisted: boolean) => act(() => void window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted })));

describe('AppShell — a page restored from the back-forward cache reloads (b3b-followup C-5c)', () => {
  it('pageshow with persisted: true reloads once; persisted: false does not', () => {
    const reload = vi.fn();
    show(reload);
    pageshow(false);
    expect(reload).not.toHaveBeenCalled();
    pageshow(true);
    expect(reload).toHaveBeenCalledTimes(1);
  });

  it('after unmount the listener is gone', () => {
    const reload = vi.fn();
    const { unmount } = show(reload);
    unmount();
    pageshow(true);
    expect(reload).not.toHaveBeenCalled();
  });
});

// PROPOSED (b3b-followup C-8; UX + SEC to confirm), typed here independently of src/copy.
const SIGN_OUT_FAILED = 'You may still be signed in on this device. Press Sign out to try again.';
/** Resolves after a 20 ms timer, as a real network answer does: always after the menu has closed. */
const later = <T,>(value: T) => new Promise<T>((resolve) => setTimeout(() => resolve(value), 20));

async function signOutFromMenu(u: ReturnType<typeof userEvent.setup>) {
  await u.click(screen.getByRole('button', { name: 'Sample Person · Account' }));
  await u.click(await screen.findByRole('menuitem', { name: 'Sign out' }));
}

describe('AppShell — a sign-out that did not work says so (b3b-followup C-8)', () => {
  beforeEach(() => {
    io.signOut.mockReset();
    io.assign.mockReset();
  });

  it('success: a full load of the served redirect, and no banner', async () => {
    io.signOut.mockImplementation(() => later<SignOutResult>({ kind: 'signed-out', redirect: '/login' }));
    const u = userEvent.setup();
    show(vi.fn());
    await signOutFromMenu(u);
    await waitFor(() => expect(io.assign).toHaveBeenCalledWith('/login'));
    expect(screen.queryByText(SIGN_OUT_FAILED)).toBeNull();
  });

  for (const [kind, heading] of [
    ['unreachable', "We couldn't reach the server."],
    ['server-error', 'Something went wrong on our side.'],
  ] as const) {
    it(`${kind}: the banner by cause with the sentence, focus on its heading after the menu has closed; never a navigation`, async () => {
      io.signOut.mockImplementation(() => later<SignOutResult>({ kind }));
      const u = userEvent.setup();
      show(vi.fn());
      await signOutFromMenu(u);
      expect(await screen.findByText(SIGN_OUT_FAILED)).toBeInTheDocument();
      const h = screen.getByText(heading);
      await waitFor(() => expect(h).toHaveFocus());
      // Still there after every pending timer: the menu's own return-focus did not take it back.
      await act(() => new Promise((r) => setTimeout(r, 50)));
      expect(h).toHaveFocus();
      expect(io.assign).not.toHaveBeenCalled();
    });
  }

  it('an answer that arrives at once (no network wait) still leaves focus on the banner, not on the menu button', async () => {
    io.signOut.mockResolvedValue({ kind: 'unreachable' });
    const u = userEvent.setup();
    show(vi.fn());
    await signOutFromMenu(u);
    const h = await screen.findByText("We couldn't reach the server.");
    await act(() => new Promise((r) => setTimeout(r, 50)));
    expect(h).toHaveFocus();
  });

  it('a second identical failure moves focus to the banner again', async () => {
    io.signOut.mockImplementation(() => later<SignOutResult>({ kind: 'unreachable' }));
    const u = userEvent.setup();
    show(vi.fn());
    await signOutFromMenu(u);
    await waitFor(() => expect(screen.getByText("We couldn't reach the server.")).toHaveFocus());
    await signOutFromMenu(u);
    await waitFor(() => expect(io.signOut).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(screen.getByText("We couldn't reach the server.")).toHaveFocus());
    await act(() => new Promise((r) => setTimeout(r, 50)));
    expect(screen.getByText("We couldn't reach the server.")).toHaveFocus();
    expect(screen.getAllByText(SIGN_OUT_FAILED)).toHaveLength(1);
  });
});
