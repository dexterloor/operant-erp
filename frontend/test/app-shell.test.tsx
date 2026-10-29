import { act, render } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { AppShell } from '@/app/(app)/AppShell';

// b3b-followup C-5c (design §6.8, L-11): a signed-in page brought back by Back from the browser's back-forward cache is
// reloaded, so it never shows what an earlier session (signed out since, or replaced) left on screen. SEC to confirm.
vi.mock('next/navigation', () => ({ usePathname: () => '/admin/users' }));

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
