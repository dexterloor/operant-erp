import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { RolePage } from '@/app/(app)/admin/roles/[id]/RolePage';
import type { Role } from '@/lib/api/roles';
import { PAGE_STATES } from '@/copy/shell';
import { formatDate } from '@/lib/format/dates';
import { ROLE_A, TZ, json, problem, renderWired, role, stubFetch } from './harness';

// ux-copy-b3b.md C7-2 and b3b-design.md §9.4: the role page wires the checklist's Save changes; a 200 then a GetRole
// re-read passes the role without a remount; a failed re-read shows the region error; 403 → ST-4b; 404 → ST-5.

afterEach(() => vi.unstubAllGlobals());

const PERMS = [
  { code: 'role.view', module: 'Modules\\UserManagement', description: 'role: view', sod_rows: [] },
  { code: 'role.edit', module: 'Modules\\UserManagement', description: 'role: edit', sod_rows: [] },
];
const BUYER = role(ROLE_A, 'Buyer', ['role.view']);
const show = (canEdit = true, r: Role = BUYER) => renderWired(<RolePage initial={{ role: r, permissions: PERMS }} canEdit={canEdit} />);

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
