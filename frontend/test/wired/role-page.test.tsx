import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
// Branch R (restore-role-design.md F-1 L-13): RolePage refreshes the router cache after a restore; `refresh` is a spy.
const nav = vi.hoisted(() => ({ refresh: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ refresh: nav.refresh }) }));
import { BffProvider } from '@/app/(app)/BffProvider';
import { RolePage } from '@/app/(app)/admin/roles/[id]/RolePage';
import type { Role } from '@/lib/api/roles';
import { PAGE_STATES } from '@/copy/shell';
import { formatDate } from '@/lib/format/dates';
import { TooltipProvider } from '@/components/ui/Tooltip';
import { ROLE_A, SID, TZ, json, problem, refused, renderWired, role, stubFetch } from './harness';

// ux-copy-b3b.md C7-2 and b3b-design.md §9.4: the role page wires the checklist's Save changes; a 200 then a GetRole
// re-read passes the role without a remount; a failed re-read shows the region error; 403 → ST-4b; 404 → ST-5.

afterEach(() => {
  vi.unstubAllGlobals();
  nav.refresh.mockClear();
});

const PERMS = [
  { code: 'role.view', module: 'Modules\\UserManagement', description: 'role: view', sod_rows: [] },
  { code: 'role.edit', module: 'Modules\\UserManagement', description: 'role: edit', sod_rows: [] },
];
const BUYER = role(ROLE_A, 'Buyer', ['role.view']);
const show = (canEdit = true, r: Role = BUYER, canRestore = false) =>
  renderWired(<RolePage initial={{ role: r, permissions: PERMS }} canEdit={canEdit} canRestore={canRestore} />);

async function tickEditAndSave(u: ReturnType<typeof userEvent.setup>) {
  await u.click(screen.getByRole('checkbox', { name: 'Edit roles' }));
  await u.click(screen.getByRole('button', { name: 'Save changes' }));
}

describe('/admin/roles/[id] — wired', () => {
  it('h1 is the served role name', () => {
    show();
    expect(screen.getByRole('heading', { level: 1, name: 'Buyer' })).toBeInTheDocument();
  });

  it('Save changes → PATCH then GetRole; the saved line; the checklist shows the re-read codes', async () => {
    const saved = role(ROLE_A, 'Buyer', ['role.edit', 'role.view']);
    const calls = stubFetch([json(200, saved), json(200, saved)]);
    const u = userEvent.setup();
    show();
    await tickEditAndSave(u);
    expect(await screen.findByText('Your changes to this role are saved.')).toBeInTheDocument();
    expect(calls.map((c) => c.method)).toEqual(['PATCH', 'GET']);
    expect(screen.getByRole('checkbox', { name: 'Edit roles' })).toBeChecked();
    expect(screen.queryByRole('button', { name: 'Save changes' })).toBeNull();
  });

  it('a 404 on Save: "Not saved" with ST-5\'s RULED words as one copy key', async () => {
    stubFetch([problem(404, 'not-found')]);
    const u = userEvent.setup();
    show();
    await tickEditAndSave(u);
    expect(await screen.findByText(PAGE_STATES.notFoundInline)).toBeInTheDocument();
  });

  it('saved but the re-read failed: the region error "We couldn\'t load this role again…", focus on its heading', async () => {
    stubFetch([json(200, role(ROLE_A, 'Buyer', ['role.edit', 'role.view'])), json(503, {})]);
    const u = userEvent.setup();
    show();
    await tickEditAndSave(u);
    const heading = await screen.findByRole('heading', { name: 'Something went wrong on our side.' });
    expect(screen.getByText("We couldn't load this role again, so what's shown may be out of date.")).toBeInTheDocument();
    await waitFor(() => expect(heading).toHaveFocus());
  });

  it('the re-read answers 403 → ST-4b for roles; 404 → ST-5 back to the roles list', async () => {
    stubFetch([json(200, BUYER), problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    const { unmount } = show();
    await tickEditAndSave(u);
    expect(await screen.findByRole('heading', { level: 1, name: "You don't have access to roles." })).toBeInTheDocument();
    expect(screen.getByText(/needs the/)).toHaveTextContent('This needs the View roles and their permissions permission. Your workspace administrator can grant it.');
    unmount();
    stubFetch([json(200, BUYER), problem(404, 'not-found')]);
    show();
    await tickEditAndSave(u);
    expect(await screen.findByRole('link', { name: 'Back to the list' })).toHaveAttribute('href', '/admin/roles');
  });
});

describe('/admin/roles/[id] — a deleted role is read-only, with its date (b3b-followup C-7, D-28)', () => {
  const DELETED = { ...role(ROLE_A, 'Buyer', ['role.view']), archived_at: '2026-09-02T10:00:00Z' };

  it('an archived role: the banner with its date in the session zone; the read-only list (no checkbox), no Save or Discard', () => {
    show(true, DELETED);
    // PROPOSED copy (UX to confirm), typed here independently of src/copy.
    expect(screen.getByText((_, el) => el?.tagName === 'P' && el.textContent === `This role was deleted on ${formatDate(DELETED.archived_at, TZ)}.`)).toBeInTheDocument();
    expect(screen.queryByRole('checkbox')).toBeNull();
    expect(screen.getByText('View roles and their permissions')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Save changes' })).toBeNull();
    expect(screen.queryByRole('button', { name: /Discard/ })).toBeNull();
    // A persistent banner, not an announcement: no live region of its own.
    expect(document.querySelector('[role="status"] time, [role="alert"] time')).toBeNull();
  });

  it('a live role with edit rights: no banner, and the boxes are there to tick', () => {
    show(true, BUYER);
    expect(screen.queryByText(/This role was deleted on/)).toBeNull();
    expect(screen.getByRole('checkbox', { name: 'Edit roles' })).toBeInTheDocument();
  });
});

describe('/admin/roles/[id] — Restore role (restore-role-design.md Revision 2 F-2, F-5)', () => {
  const DELETED_AT = '2026-09-02T10:00:00Z';
  const DELETED: Role = { ...role(ROLE_A, 'Buyer', ['role.view']), archived_at: DELETED_AT };
  const LIVE: Role = { ...DELETED, archived_at: null };
  const RESTORE = 'Restore role';
  const restoreButton = () => screen.queryByRole('button', { name: RESTORE });
  const bannerText = () => screen.queryByText(/This role was deleted on/);
  /** The checklist's root region: the one status region inside the checklist and outside every group `section`. */
  const checklistRegion = () => {
    const root = screen.getByText('View roles and their permissions').closest('section')!.parentElement!.parentElement!;
    const outside = Array.from(root.querySelectorAll('[role="status"]')).filter((r) => r.closest('section[aria-labelledby]') === null);
    expect(outside).toHaveLength(1);
    return outside[0]!;
  };
  /** The page's own after-message region: the first status region on the page (above the deleted banner). */
  const pageRegion = () => screen.getAllByRole('status')[0]!;
  const sessionProblem = (status: number, type: string) =>
    new Response(JSON.stringify({ type, title: 'x', status }), { status, headers: { 'content-type': 'application/problem+json' } });

  it('T-3: the button only for canRestore + deleted + not built-in; otherwise none, never a disabled one', () => {
    const { unmount } = show(true, DELETED, true);
    expect(restoreButton()).toBeInTheDocument();
    expect(restoreButton()).not.toHaveAttribute('aria-disabled');
    unmount();
    for (const [r, can] of [
      [LIVE, true],
      [{ ...DELETED, is_system: true }, true],
      [DELETED, false],
    ] as const) {
      const v = show(true, r, can);
      expect(restoreButton()).toBeNull();
      expect(document.querySelector('[aria-disabled="true"]')).toBeNull();
      v.unmount();
    }
  });

  it('T-4: one POST to /bff/api/roles/{id}/restoration, no body, no Idempotency-Key, with X-ERP-Session', async () => {
    const calls = stubFetch([json(200, LIVE)]);
    const u = userEvent.setup();
    show(true, DELETED, true);
    await u.click(restoreButton()!);
    await screen.findByText('Buyer was restored.');
    expect(calls.map((c) => c.method)).toEqual(['POST']);
    expect(calls[0]!.url).toBe(`/bff/api/roles/${ROLE_A}/restoration`);
    expect(calls[0]!.body).toBeNull();
    expect(calls[0]!.headers.has('idempotency-key')).toBe(false);
    expect(calls[0]!.headers.get('x-erp-session')).toBe(SID);
  });

  it('T-5: banner gone; the h1 focused, then the announcement; both regions are the nodes they were; the boxes editable', async () => {
    stubFetch([json(200, LIVE)]);
    const u = userEvent.setup();
    show(true, DELETED, true);
    const region = pageRegion();
    const listRegion = checklistRegion();
    expect(region).toHaveTextContent('');
    const h1 = screen.getByRole('heading', { level: 1, name: 'Buyer' });
    // focusWhenInserted (test/focus-timing.test.tsx): where focus is at the first mutation that shows the message.
    const seen: Array<Element | null> = [];
    const observer = new MutationObserver(() => {
      if (seen.length === 0 && region.textContent === 'Buyer was restored.') seen.push(document.activeElement);
    });
    observer.observe(document.body, { childList: true, subtree: true, characterData: true });
    await u.click(restoreButton()!);
    await waitFor(() => expect(region).toHaveTextContent('Buyer was restored.'));
    observer.disconnect();
    expect(seen[0]).toBe(h1);
    expect(h1).toHaveFocus();
    expect(bannerText()).toBeNull();
    expect(restoreButton()).toBeNull();
    expect(pageRegion()).toBe(region);
    expect(checklistRegion()).toBe(listRegion);
    expect(screen.getByRole('checkbox', { name: 'Edit roles' })).toBeInTheDocument();
  });

  it('T-8: 409 is_not_archived → GetRole live (renamed) → "{new name} was already restored.", no refusal; then refused-first-then-already leaves no Not restored', async () => {
    const renamed = { ...LIVE, name: 'Purchasing' };
    const calls = stubFetch([refused('role.is_not_archived', 'X-planted sentence'), json(200, renamed)]);
    const u = userEvent.setup();
    const { unmount } = show(true, DELETED, true);
    await u.click(restoreButton()!);
    expect(await screen.findByText('Purchasing was already restored.')).toBeInTheDocument();
    expect(calls.map((c) => c.method)).toEqual(['POST', 'GET']);
    expect(screen.queryByText('Not restored')).toBeNull();
    expect(screen.queryByText('X-planted sentence')).toBeNull();
    unmount();
    stubFetch([refused('role.name_taken', 'X-planted name sentence'), refused('role.is_not_archived', 'X-planted sentence'), json(200, LIVE)]);
    show(true, DELETED, true);
    await u.click(restoreButton()!);
    expect(await screen.findByText('Not restored')).toBeInTheDocument();
    await u.click(restoreButton()!);
    expect(await screen.findByText('Buyer was already restored.')).toBeInTheDocument();
    expect(screen.queryByText('Not restored')).toBeNull();
  });

  it('T-8b: 409 is_not_archived → GetRole deleted again (new date) → Not restored + the no-message body, the new date, no "already"', async () => {
    const again = { ...DELETED, archived_at: '2026-10-30T10:00:00Z' };
    stubFetch([refused('role.is_not_archived', 'X-planted sentence'), json(200, again)]);
    const u = userEvent.setup();
    show(true, DELETED, true);
    await u.click(restoreButton()!);
    expect(await screen.findByText('Not restored')).toBeInTheDocument();
    expect(screen.getByText("This change wasn't made. Reload the page to see where things stand, then try again.")).toBeInTheDocument();
    expect(bannerText()).toHaveTextContent(`This role was deleted on ${formatDate(again.archived_at, TZ)}.`);
    expect(screen.queryByText(/already restored/)).toBeNull();
    expect(restoreButton()).toBeInTheDocument();
  });

  it('T-9: 409 is_not_archived → the re-read fails → the page takes over (region error / ST-5 / ST-4b), no Not restored, no announcement', async () => {
    const u = userEvent.setup();
    stubFetch([refused('role.is_not_archived', 'X-planted sentence'), json(500, {})]);
    let v = show(true, DELETED, true);
    await u.click(restoreButton()!);
    expect(await screen.findByText("We couldn't load this role again, so what's shown may be out of date.")).toBeInTheDocument();
    expect(screen.queryByText('Not restored')).toBeNull();
    expect(pageRegion()).toHaveTextContent('');
    v.unmount();
    stubFetch([refused('role.is_not_archived', 'X-planted sentence'), problem(404, 'not-found')]);
    v = show(true, DELETED, true);
    await u.click(restoreButton()!);
    expect(await screen.findByRole('link', { name: 'Back to the list' })).toHaveAttribute('href', '/admin/roles');
    expect(screen.queryByText('Not restored')).toBeNull();
    v.unmount();
    stubFetch([refused('role.is_not_archived', 'X-planted sentence'), problem(403, 'permission-denied')]);
    v = show(true, DELETED, true);
    await u.click(restoreButton()!);
    expect(await screen.findByRole('heading', { level: 1, name: "You don't have access to roles." })).toBeInTheDocument();
    expect(screen.queryByText('Not restored')).toBeNull();
    v.unmount();
    // L-11: a first press refused, then a second press whose re-read fails → no Not restored left behind.
    stubFetch([refused('role.name_taken', 'X-planted name sentence'), refused('role.is_not_archived', 'X-planted sentence'), json(500, {})]);
    show(true, DELETED, true);
    await u.click(restoreButton()!);
    expect(await screen.findByText('Not restored')).toBeInTheDocument();
    await u.click(restoreButton()!);
    expect(await screen.findByText("We couldn't load this role again, so what's shown may be out of date.")).toBeInTheDocument();
    expect(screen.queryByText('Not restored')).toBeNull();
  });

  it('T-10: unreachable / 503 → the R1-1 heading + the interrupted body; the button stays; a second press sends a second POST; refused then restored leaves no Not restored', async () => {
    const u = userEvent.setup();
    const calls = stubFetch([
      () => {
        // fetch rejects (offline): the stub's handler throws, so the mocked fetch's promise rejects.
        throw new TypeError('offline');
      },
      json(503, {}),
    ]);
    const body = "We can't tell whether Buyer was restored. Press Restore role to try again.";
    const v = show(true, DELETED, true);
    await u.click(restoreButton()!);
    expect(await screen.findByText("We couldn't reach the server.")).toBeInTheDocument();
    expect(screen.getByText(body)).toBeInTheDocument();
    expect(restoreButton()).toBeInTheDocument();
    await u.click(restoreButton()!);
    expect(await screen.findByText('Something went wrong on our side.')).toBeInTheDocument();
    expect(screen.getByText(body)).toBeInTheDocument();
    expect(calls.map((c) => c.method)).toEqual(['POST', 'POST']);
    v.unmount();
    stubFetch([refused('role.name_taken', 'X-planted name sentence'), json(200, LIVE)]);
    show(true, DELETED, true);
    await u.click(restoreButton()!);
    expect(await screen.findByText('Not restored')).toBeInTheDocument();
    // A real refusal code with a planted sentence: the body is the server's text, never a client row for that code (M-6).
    expect(screen.getByText('X-planted name sentence')).toBeInTheDocument();
    await u.click(restoreButton()!);
    expect(await screen.findByText('Buyer was restored.')).toBeInTheDocument();
    expect(screen.queryByText('Not restored')).toBeNull();
  });

  it('T-11: 403 → Not restored + R1-5 body, "Delete and restore roles" in <em>; never the server detail', async () => {
    stubFetch([problem(403, 'permission-denied', { detail: 'X-planted detail' })]);
    const u = userEvent.setup();
    show(true, DELETED, true);
    await u.click(restoreButton()!);
    expect(await screen.findByText('Not restored')).toBeInTheDocument();
    const em = screen.getByText('Delete and restore roles');
    expect(em.tagName).toBe('EM');
    expect(em.parentElement).toHaveTextContent('This needs the Delete and restore roles permission, which you no longer have. Your workspace administrator can grant it.');
    expect(screen.queryByText(/X-planted detail/)).toBeNull();
  });

  it('T-12: 404 → Not restored + ST-5\'s inline words', async () => {
    stubFetch([problem(404, 'not-found')]);
    const u = userEvent.setup();
    show(true, DELETED, true);
    await u.click(restoreButton()!);
    expect(await screen.findByText('Not restored')).toBeInTheDocument();
    expect(screen.getByText(PAGE_STATES.notFoundInline)).toBeInTheDocument();
  });

  it('T-13: 401 (session ended) → nothing rendered in the page (the caller navigates to sign-in)', async () => {
    stubFetch([sessionProblem(401, 'urn:erp:bff:session-ended')]);
    // The provider's navigation is a full document load, which jsdom reports on stderr as "Not implemented: navigation";
    // the transport half (navigate called once) is pinned in role-save.test.ts with an injected stub.
    const u = userEvent.setup();
    show(true, DELETED, true);
    await u.click(restoreButton()!);
    await waitFor(() => expect(document.querySelector('[aria-busy="true"]')).toBeNull());
    expect(screen.queryByText('Not restored')).toBeNull();
    expect(screen.queryByText(/We can't tell whether/)).toBeNull();
    expect(pageRegion()).toHaveTextContent('');
  });

  it('T-19: a 200 whose body is not a role → Something went wrong + the interrupted body', async () => {
    stubFetch([json(200, { nope: 1 })]);
    const u = userEvent.setup();
    show(true, DELETED, true);
    await u.click(restoreButton()!);
    expect(await screen.findByText('Something went wrong on our side.')).toBeInTheDocument();
    expect(screen.getByText("We can't tell whether Buyer was restored. Press Restore role to try again.")).toBeInTheDocument();
  });

  it('T-20: 409 session-changed → nothing rendered; the reload is called once', async () => {
    stubFetch([sessionProblem(409, 'urn:erp:bff:session-changed')]);
    const reload = vi.fn();
    const u = userEvent.setup();
    render(
      <TooltipProvider>
        <BffProvider session={{ sid: SID, tz: TZ }} reload={reload}>
          <RolePage initial={{ role: DELETED, permissions: PERMS }} canEdit canRestore />
        </BffProvider>
      </TooltipProvider>,
    );
    await u.click(restoreButton()!);
    await waitFor(() => expect(reload).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(document.querySelector('[aria-busy="true"]')).toBeNull());
    expect(screen.queryByText('Not restored')).toBeNull();
    expect(screen.queryByText(/We can't tell whether/)).toBeNull();
  });

  it('T-21 (branch R): refresh once, after the message is on screen, on restored and on already; never on a refusal, interrupted or page-took-over', async () => {
    const u = userEvent.setup();
    let atRefresh = '';
    nav.refresh.mockImplementation(() => {
      atRefresh = pageRegion().textContent ?? '';
    });
    stubFetch([json(200, LIVE)]);
    let v = show(true, DELETED, true);
    await u.click(restoreButton()!);
    await screen.findByText('Buyer was restored.');
    await waitFor(() => expect(nav.refresh).toHaveBeenCalledTimes(1));
    expect(atRefresh).toBe('Buyer was restored.');
    v.unmount();
    nav.refresh.mockClear();
    stubFetch([refused('role.is_not_archived', 'X-planted sentence'), json(200, LIVE)]);
    v = show(true, DELETED, true);
    await u.click(restoreButton()!);
    await screen.findByText('Buyer was already restored.');
    await waitFor(() => expect(nav.refresh).toHaveBeenCalledTimes(1));
    expect(atRefresh).toBe('Buyer was already restored.');
    v.unmount();
    nav.refresh.mockClear();
    for (const replies of [
      [refused('role.name_taken', 'X-planted name sentence')],
      [json(503, {})],
      [refused('role.is_not_archived', 'X-planted sentence'), json(500, {})],
    ]) {
      stubFetch(replies);
      v = show(true, DELETED, true);
      await u.click(restoreButton()!);
      await waitFor(() => expect(document.querySelector('[aria-busy="true"]')).toBeNull());
      await new Promise((r) => setTimeout(r, 20));
      v.unmount();
    }
    expect(nav.refresh).not.toHaveBeenCalled();
    nav.refresh.mockReset();
  });
});
