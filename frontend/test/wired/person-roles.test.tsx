import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { PersonPage, type PersonPageGates } from '@/app/(app)/admin/users/[id]/PersonPage';
import { PERSON_DELETED_WHILE_OPEN, PICKER, ROLES_FORBIDDEN_SENTENCE } from '@/copy/roles';
import type { PersonView } from '@/lib/people/load';
import { ID, PROPOSAL_1, ROLE_A, ROLE_B, VIEWER_ID, empty, json, later, offered, page, problem, proposal, renderWired, role, stubFetch, user, type Reply } from './harness';

// b3b-design.md §10's per-read re-read table (Revision 4), A-10, T-RR, and ux-copy-b3b.md C6-1 with RED round 2 R2-M1:
// the page — never the B-4 components — decides what a re-read's 403 / 404 / newly-deleted answer does, and the
// "deleted while open" sentence is announced from a page-level region that is still in the DOM after the re-render.

afterEach(() => vi.unstubAllGlobals());

const BUYER = role(ROLE_A, 'Buyer');
const FINANCE = role(ROLE_B, 'Finance');
const ALL: PersonPageGates = { delete: true, assign: true, view: true, confirm: true };
const view = (over: Partial<PersonView> = {}): PersonView => ({
  person: user(),
  access: { offered: [offered(ROLE_A, 'Buyer'), offered(ROLE_B, 'Finance')], pending: [], page: page([]) },
  catalogue: { roles: [BUYER, FINANCE], permissions: [] },
  ...over,
});
const show = (gates: PersonPageGates = ALL, initial: PersonView = view()) => renderWired(<PersonPage initial={initial} viewerId={VIEWER_ID} gates={gates} />);

/** The re-read's answers in order: GetUser, ListOfferedRoles, the walk's first page, ListRoles, ListPermissions. */
const rereadOf = (over: Partial<Record<'user' | 'offered' | 'walk' | 'roles' | 'permissions', Reply>> = {}): Reply[] => [
  over.user ?? json(200, user({ roles: [{ id: ROLE_A, name: 'Buyer' }] })),
  over.offered ?? json(200, page([offered(ROLE_A, 'Buyer'), offered(ROLE_B, 'Finance')])),
  over.walk ?? json(200, page([])),
  over.roles ?? json(200, page([BUYER, FINANCE])),
  over.permissions ?? json(200, page([])),
];
const pageStatus = () => document.querySelector<HTMLElement>('section > .visually-hidden[role="status"]')!;

async function giveBuyer(u: ReturnType<typeof userEvent.setup>) {
  await u.click(screen.getByRole('checkbox', { name: /Buyer/ }));
  await u.click(screen.getByRole('button', { name: PICKER.save }));
}

describe('the person page wires the picker (§9.2) and re-reads the whole page (T-RR)', () => {
  it('Save roles → AssignRole, then GetUser + ListOfferedRoles + the pending walk (user, status=pending) + ListRoles + ListPermissions', async () => {
    const calls = stubFetch([json(201, BUYER), ...rereadOf()]);
    const u = userEvent.setup();
    show();
    await giveBuyer(u);
    await waitFor(() => expect(calls).toHaveLength(6));
    expect(calls.map((c) => `${c.method} ${c.url}`)).toEqual([
      `POST /bff/api/users/${ID}/roles`,
      `GET /bff/api/users/${ID}`,
      `GET /bff/api/users/${ID}/offered-roles`,
      `GET /bff/api/role-grant-proposals?user=${ID}&status=pending`,
      'GET /bff/api/roles',
      'GET /bff/api/permissions',
    ]);
    await waitFor(() => expect(screen.getByRole('button', { name: PICKER.save })).toHaveFocus());
  });

  it('a viewer with Delete and without role.assign: no picker, no offered-roles or proposals read ever; delete works (M-4)', async () => {
    const calls = stubFetch([empty(204), json(200, user({ archived_at: '2026-10-28T10:00:00Z' }))]);
    const u = userEvent.setup();
    show({ delete: true, assign: false, view: false, confirm: false }, view({ access: null, catalogue: null }));
    expect(screen.queryByRole('button', { name: PICKER.save })).toBeNull();
    await u.click(screen.getByRole('button', { name: 'Delete person' }));
    await u.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Delete person' }));
    await screen.findByText(/This person was deleted on/);
    expect(calls.map((c) => c.url)).toEqual([`/bff/api/users/${ID}`, `/bff/api/users/${ID}`]);
  });

  it('a viewer without role.assign / role.view restores a person: only the restore is sent, no role read (M-4)', async () => {
    const calls = stubFetch([json(200, user())]);
    const u = userEvent.setup();
    show({ delete: true, assign: false, view: false, confirm: false }, view({ person: user({ archived_at: '2026-10-28T10:00:00Z' }), access: null, catalogue: null }));
    await u.click(screen.getByRole('button', { name: 'Restore person' }));
    await u.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Restore person' }));
    await screen.findByText(/was restored/);
    await new Promise((r) => setTimeout(r, 20));
    expect(calls.map((c) => c.url)).toEqual([`/bff/api/users/${ID}/restoration`]);
  });

  it('a restore with role.assign re-reads the person\'s roles (they were read for a deleted person)', async () => {
    const calls = stubFetch([json(200, user()), ...rereadOf({ user: json(200, user()) })]);
    const u = userEvent.setup();
    show(ALL, view({ person: user({ archived_at: '2026-10-28T10:00:00Z' }) }));
    await u.click(screen.getByRole('button', { name: 'Restore person' }));
    await u.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Restore person' }));
    await waitFor(() => expect(calls).toHaveLength(6));
    expect(calls[2]!.url).toBe(`/bff/api/users/${ID}/offered-roles`);
  });

  it('the page re-read runs under the save\'s 60 s deadline: a read still out at 60 s is aborted (the picker then says it could not load)', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    try {
      const calls = stubFetch([
        json(201, BUYER),
        later(15_000, () => json(200, user({ roles: [{ id: ROLE_A, name: 'Buyer' }] }))),
        later(15_000, () => json(200, page([offered(ROLE_A, 'Buyer'), offered(ROLE_B, 'Finance')]))),
        later(15_000, () => json(200, page([]))),
        later(19_000, () => json(200, page([BUYER, FINANCE]))),
      ]);
      const u = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
      show();
      await giveBuyer(u);
      for (let i = 0; i < 70; i += 1) await vi.advanceTimersByTimeAsync(1_000);
      // The ListRoles reply (due at 64 s) was aborted at 60 s, so ListPermissions was never asked.
      expect(calls.map((c) => c.url).at(-1)).toBe('/bff/api/roles');
      expect(await screen.findByRole('button', { name: PICKER_BANNERS_LOAD })).toBeInTheDocument();
    } finally {
      vi.useRealTimers();
    }
  });

  it('a failed page of the walk → failed; the old props stay and the picker shows its own failure (Load roles again)', async () => {
    stubFetch([json(201, BUYER), ...rereadOf({ walk: json(500, {}) })]);
    const u = userEvent.setup();
    show();
    await giveBuyer(u);
    expect(await screen.findByRole('button', { name: PICKER_BANNERS_LOAD })).toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: /Finance/ })).toBeInTheDocument();
  });
});

const PICKER_BANNERS_LOAD = 'Load roles again';

describe('§10 — a re-read answer the page takes over (A-10)', () => {
  it('ListOfferedRoles 403 (role.assign lost) after a successful save: the R1-5 body at the top, picker and Waiting gone, the page stays', async () => {
    // No walk after the 403: the access read stops there; the catalogue is still read (role.view held).
    const r = rereadOf({ offered: problem(403, 'permission-denied') });
    stubFetch([json(201, BUYER), r[0]!, r[1]!, r[3]!, r[4]!]);
    const u = userEvent.setup();
    show(ALL, view({ access: { offered: [offered(ROLE_A, 'Buyer'), offered(ROLE_B, 'Finance')], pending: [proposal(PROPOSAL_1, ROLE_B, 'Finance')], page: page([proposal(PROPOSAL_1, ROLE_B, 'Finance')]) } }));
    expect(screen.getByRole('heading', { name: 'Waiting for an owner' })).toBeInTheDocument();
    await giveBuyer(u);
    await waitFor(() => expect(screen.queryByRole('button', { name: PICKER.save })).toBeNull());
    expect(screen.getByText(/needs the/, { selector: 'p' })).toHaveTextContent(ROLES_FORBIDDEN_SENTENCE);
    expect(screen.queryByRole('heading', { name: 'Waiting for an owner' })).toBeNull();
    expect(screen.getByRole('heading', { level: 1, name: 'Ada Lovelace' })).toHaveFocus();
    expect(screen.getByRole('button', { name: 'Delete person' })).toBeInTheDocument();
    expect(pageStatus()).toHaveTextContent(ROLES_FORBIDDEN_SENTENCE);
  });

  it('ListRoles 403 (role.view lost): no banner; the panel loses its permissions (R4-2), the picker stays', async () => {
    stubFetch([json(201, BUYER), ...rereadOf({ roles: problem(403, 'permission-denied') }).slice(0, 4)]);
    const u = userEvent.setup();
    show();
    await giveBuyer(u);
    expect(await screen.findByText(PICKER.permissionsHidden)).toBeInTheDocument();
    expect(screen.queryByText(/needs the/)).toBeNull();
    expect(screen.getByRole('button', { name: PICKER.save })).toBeInTheDocument();
  });

  it('GetUser 403 → the ST-4b page (the only swap to it)', async () => {
    stubFetch([json(201, BUYER), problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    show();
    await giveBuyer(u);
    expect(await screen.findByRole('heading', { level: 1, name: "You don't have access to people." })).toBeInTheDocument();
  });

  it('a 404 on any read → ST-5, with no other sentence', async () => {
    stubFetch([json(201, BUYER), ...rereadOf({ offered: problem(404, 'not-found') }).slice(0, 2)]);
    const u = userEvent.setup();
    show();
    await giveBuyer(u);
    expect(await screen.findByRole('heading', { level: 1, name: "We couldn't find that." })).toBeInTheDocument();
    expect(screen.queryByText(/needs the|was deleted|couldn't load/)).toBeNull();
  });

  it('GetUser 200 with archived_at newly set: the deleted page (B-4 banner), the picker gone, the sentence spoken from a region still in the DOM (R2-M1)', async () => {
    stubFetch([json(201, BUYER), ...rereadOf({ user: json(200, user({ archived_at: '2026-10-28T10:00:00Z' })) })]);
    const u = userEvent.setup();
    show();
    const region = pageStatus();
    await giveBuyer(u);
    expect(await screen.findByText(/This person was deleted on/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: PICKER.save })).toBeNull();
    expect(region.isConnected).toBe(true);
    expect(region).toHaveTextContent(PERSON_DELETED_WHILE_OPEN);
    expect(screen.getByRole('heading', { level: 1, name: 'Ada Lovelace' })).toHaveFocus();
  });

  it('after a save that did not finish, the "deleted while open" sentence is the page banner body under "Not saved", outside the picker', async () => {
    stubFetch([problem(409, 'business-rule-violated', { rule: 'user_role.subject_is_deleted', message: 'They were deleted.' }), ...rereadOf({ user: json(200, user({ archived_at: '2026-10-28T10:00:00Z' })) })]);
    const u = userEvent.setup();
    show();
    await giveBuyer(u);
    const heading = await screen.findByText('Not saved');
    expect(heading.closest('div')!.parentElement).toHaveTextContent(PERSON_DELETED_WHILE_OPEN);
    expect(document.querySelector('[data-surface="role-picker"]')).toBeNull();
    await waitFor(() => expect(heading).toHaveFocus());
  });
});

describe('the Waiting group (§9.3) through the page', () => {
  it('Confirm → POST …/confirmation, then the whole re-read; the proposal leaves the group', async () => {
    const pending = proposal(PROPOSAL_1, ROLE_B, 'Finance');
    const calls = stubFetch([json(200, { ...pending, status: 'confirmed' }), ...rereadOf()]);
    const u = userEvent.setup();
    show(ALL, view({ access: { offered: [offered(ROLE_A, 'Buyer'), offered(ROLE_B, 'Finance')], pending: [pending], page: page([pending]) } }));
    await u.click(screen.getByRole('button', { name: 'Confirm' }));
    await waitFor(() => expect(screen.queryByRole('heading', { name: 'Waiting for an owner' })).toBeNull());
    expect(calls[0]!.url).toBe(`/bff/api/role-grant-proposals/${PROPOSAL_1}/confirmation`);
    expect(calls).toHaveLength(6);
  });
});
