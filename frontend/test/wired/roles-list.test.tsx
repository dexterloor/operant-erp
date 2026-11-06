import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';

// b3b-followup C-4 (final-red L-1): the list keeps its view in the address through the App Router, which Next refuses to
// give outside a mounted router. The list is never made tolerant of a missing router; the test supplies one.
const nav = vi.hoisted(() => ({ replace: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ replace: nav.replace }), usePathname: () => '/admin/roles' }));
import { RolesList } from '@/app/(app)/admin/roles/RolesList';
import { SEARCH_SETTLE_MS } from '@/app/(app)/ArchivableList';
import { formatDate } from '@/lib/format/dates';
import { ROLE_A, ROLE_B, TZ, json, page, renderWired, role, stubFetch, textsOf } from './harness';

// ux-copy-b3b.md C7-1 (`/admin/roles`): columns, the Name cell (description, Built-in from the served is_system, never
// from a name), the People and Permissions counts, the empty states, the search and its announcement.

afterEach(() => vi.unstubAllGlobals());

const BUYER = { ...role(ROLE_A, 'Buyer', ['a.b', 'c.d', 'e.f']), description: 'Places orders.', holder_count: 2 };
const OWNERISH = { ...role(ROLE_B, 'Finance', []), is_system: true, holder_count: 0 };
const OLD = { ...role('r-old', 'Old', []), archived_at: '2026-01-02T10:00:00Z' };
const NEW = { ...role('r-new', 'New', []), archived_at: '2026-09-02T10:00:00Z' };
const status = () => document.querySelector<HTMLElement>('.visually-hidden[role="status"]')!;
const show = (roles = [BUYER, OWNERISH]) => renderWired(<RolesList initial={{ kind: 'ok', roles }} />);

describe('/admin/roles — the live view', () => {
  it('h1 Roles; columns Name · People · Permissions; description under the name; Built-in from is_system; counts as numbers', () => {
    show();
    expect(screen.getByRole('heading', { level: 1, name: 'Roles' })).toBeInTheDocument();
    expect(screen.getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Name', 'People', 'Permissions']);
    const [buyer, fin] = screen.getAllByRole('row').slice(1);
    expect(within(buyer!).getByRole('link', { name: 'Buyer' })).toHaveAttribute('href', `/admin/roles/${ROLE_A}`);
    expect(within(buyer!).getByText('Places orders.')).toBeInTheDocument();
    expect(within(buyer!).queryByText('Built-in')).toBeNull();
    expect(within(buyer!).getAllByRole('cell').slice(1).map((c) => c.textContent)).toEqual(['2', '3']);
    expect(within(fin!).getByText('Built-in')).toBeInTheDocument();
    expect(within(fin!).getAllByRole('cell').slice(1).map((c) => c.textContent)).toEqual(['0', '0']);
  });

  it('search by the start of a role name, case-insensitive; announced when settled', async () => {
    const u = userEvent.setup();
    show();
    const box = screen.getByRole('searchbox', { name: 'Search by name' });
    expect(box).toHaveAttribute('placeholder', 'Start of a role name');
    await u.type(box, 'bu');
    expect(screen.getAllByRole('row')).toHaveLength(2);
    await waitFor(() => expect(status()).toHaveTextContent('1 role shown. Name starts with “bu”.'));
    await u.clear(box);
    await u.type(box, 'ANCE');
    // A prefix, never a substring: "Finance" contains "ance" but does not start with it.
    expect(screen.getByText("No role's name starts with “ANCE”.", { selector: 'p' })).toBeInTheDocument();
    await u.clear(box);
    await u.type(box, 'zz');
    expect(screen.getByText("No role's name starts with “zz”.", { selector: 'p' })).toBeInTheDocument();
    await u.click(screen.getByRole('button', { name: 'Clear search' }));
    expect(box).toHaveFocus();
  });

  it('no roles at all: "No roles yet." with no action', () => {
    show([]);
    expect(screen.getByText('No roles yet.', { selector: 'p' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Clear search' })).toBeNull();
  });
});

describe('/admin/roles — the Archived view', () => {
  it('Name · Deleted on, newest deleted first, its caption; "2 roles shown. Archived."', async () => {
    const calls = stubFetch([json(200, page([OLD, NEW]))]);
    const u = userEvent.setup();
    show();
    await u.click(screen.getByRole('button', { name: 'Archived' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Archived' })).toHaveAttribute('aria-pressed', 'true'));
    expect(calls[0]!.url).toBe('/bff/api/roles?archived=true');
    expect(screen.getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Name', 'Deleted on']);
    expect(screen.getByText('Most recently deleted first')).toBeInTheDocument();
    const rows = screen.getAllByRole('row').slice(1);
    expect(rows.map((r) => within(r).getAllByRole('cell')[0]!.textContent)).toEqual(['New', 'Old']);
    expect(within(rows[0]!).getAllByRole('cell')[1]).toHaveTextContent(formatDate(NEW.archived_at, TZ));
    await waitFor(() => expect(status()).toHaveTextContent('2 roles shown. Archived.'));
  });

  it('empty: "No archived roles. Roles that are deleted appear here."; searched: the archived form', async () => {
    stubFetch([json(200, page([]))]);
    const u = userEvent.setup();
    show();
    await u.click(screen.getByRole('button', { name: 'Archived' }));
    expect(await screen.findByText('No archived roles. Roles that are deleted appear here.', { selector: 'p' })).toBeInTheDocument();
  });

  it('a failed switch: the earlier rows stay, the shared refetch body, the control still on the live view', async () => {
    stubFetch([json(500, {})]);
    const u = userEvent.setup();
    show();
    await u.click(screen.getByRole('button', { name: 'Archived' }));
    expect(await screen.findByText("The list couldn't be updated, so it still shows the earlier results.")).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Archived' })).toHaveAttribute('aria-pressed', 'false');
    expect(screen.getByText('Buyer')).toBeInTheDocument();
  });
});

describe('/admin/roles — first load failed', () => {
  it('"The list of roles couldn\'t be loaded." under the R1-1 heading, with Retry', () => {
    renderWired(<RolesList initial={{ kind: 'server-error' }} />);
    expect(screen.getByRole('heading', { name: 'Something went wrong on our side.' })).toBeInTheDocument();
    expect(screen.getByText("The list of roles couldn't be loaded.")).toBeInTheDocument();
  });
});

// FE-R4 (fe-r4-ux.md Revision 1, final rule and test list): a keystroke never writes the list's status region; the first write
// caused by typing is the settled result for the new query. A switch that succeeds while a search is settling settles it at
// once. The mount settle and a type-and-erase start nothing. Recorded with `textsOf` (every text the region held, in order).
describe('/admin/roles — FE-R4: what the status region holds while a search is unsettled', () => {
  const wait = (ms: number) => act(() => new Promise((r) => setTimeout(r, ms)));

  /** 1. The settle, not a fetch, is what starts announcements here; no fetch happens (`calls` is empty). */
  it('1. the first search, with no prior interaction, speaks only its settled result — never the previous count', async () => {
    const calls = stubFetch([]);
    const u = userEvent.setup();
    show();
    const log = textsOf(status());
    await u.type(screen.getByRole('searchbox'), 'BU');
    await waitFor(() => expect(status()).toHaveTextContent('1 role shown. Name starts with “BU”.'));
    await wait(SEARCH_SETTLE_MS + 200);
    log.stop();
    expect(log.texts).toEqual(['', '1 role shown. Name starts with “BU”.']);
    expect(log.texts).not.toContain('2 roles shown.');
    expect(calls).toHaveLength(0);
  });

  it('2. a first search that matches nothing speaks only its settled empty heading', async () => {
    stubFetch([]);
    const u = userEvent.setup();
    show();
    const log = textsOf(status());
    await u.type(screen.getByRole('searchbox'), 'zz');
    await waitFor(() => expect(status()).toHaveTextContent("No role's name starts with “zz”."));
    await wait(SEARCH_SETTLE_MS + 200);
    log.stop();
    expect(log.texts).toEqual(['', "No role's name starts with “zz”."]);
  });

  it('3. pin: after a successful Archived switch, typing leaves the region unchanged until the settle', async () => {
    stubFetch([json(200, page([OLD, NEW]))]);
    const u = userEvent.setup();
    show();
    await u.click(screen.getByRole('button', { name: 'Archived' }));
    await waitFor(() => expect(status()).toHaveTextContent('2 roles shown. Archived.'));
    const log = textsOf(status());
    await u.type(screen.getByRole('searchbox'), 'N');
    expect(status()).toHaveTextContent('2 roles shown. Archived.');
    await waitFor(() => expect(status()).toHaveTextContent('1 role shown. Name starts with “N”. Archived.'));
    log.stop();
    expect(log.texts).toEqual(['2 roles shown. Archived.', '1 role shown. Name starts with “N”. Archived.']);
  });

  it('5. a switch that succeeds while a search is settling speaks the result for the query in the box, once', async () => {
    stubFetch([json(200, page([OLD, NEW]))]);
    const u = userEvent.setup();
    show();
    const log = textsOf(status());
    await u.type(screen.getByRole('searchbox'), 'BU');
    await u.click(screen.getByRole('button', { name: 'Archived' }));
    await waitFor(() => expect(status()).toHaveTextContent("No archived role's name starts with “BU”."));
    await wait(SEARCH_SETTLE_MS + 200);
    log.stop();
    expect(log.texts).toEqual(['', "No archived role's name starts with “BU”."]);
    expect(log.texts).not.toContain('2 roles shown. Archived.');
  });

  it('7. with no interaction the mount settle speaks nothing; typing and erasing within the settle window speaks nothing', async () => {
    stubFetch([]);
    const u = userEvent.setup();
    show();
    const log = textsOf(status());
    await wait(SEARCH_SETTLE_MS + 200);
    expect(log.texts).toEqual(['']);
    await u.type(screen.getByRole('searchbox'), 'a{Backspace}');
    await wait(SEARCH_SETTLE_MS + 200);
    log.stop();
    expect(log.texts).toEqual(['']);
  });
});
