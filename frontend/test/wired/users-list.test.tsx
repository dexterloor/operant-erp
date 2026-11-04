import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// b3b-followup C-4 (final-red L-1): the list keeps its view in the address through the App Router, which Next refuses to
// give outside a mounted router. The list is never made tolerant of a missing router; the test supplies one.
const nav = vi.hoisted(() => ({ replace: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ replace: nav.replace }), usePathname: () => '/admin/users' }));
import { UsersList } from '@/app/(app)/admin/users/UsersList';
import { formatDate } from '@/lib/format/dates';
import { ID, TZ, VIEWER_ID, json, page, renderWired, stubFetch, user } from './harness';

// ux-copy-b3b.md C5-1 (`/admin/users`) and RED round 2 R2-L2: columns, cells, order, search (client-side, a
// case-insensitive email prefix), the empty states, the settled announcement, the region error on first load and the
// refetch failure that keeps the earlier rows — with the Archived control showing the view actually on screen.

afterEach(() => {
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

const ADA = user({ roles: [{ id: 'r1', name: 'Buyer' }, { id: 'r2', name: 'Finance' }] });
const ME = user({ id: VIEWER_ID, email: 'me@example.test', display_name: 'Me Myself', is_active: false });
const OLD = user({ id: 'a1', email: 'old@example.test', display_name: 'Old One', archived_at: '2026-01-02T10:00:00Z' });
const NEW = user({ id: 'a2', email: 'new@example.test', display_name: 'New One', archived_at: '2026-09-02T10:00:00Z' });

const status = () => document.querySelector<HTMLElement>('.visually-hidden[role="status"]')!;
const show = (ui = <UsersList initial={{ kind: 'ok', users: [ADA, ME] }} viewerId={VIEWER_ID} />) => renderWired(ui);

describe('/admin/users — the live view', () => {
  it('h1 Users; columns Name · Email · Roles · Status; roles joined, "No roles", Active / Switched off, "{name} (you)"', () => {
    show();
    expect(screen.getByRole('heading', { level: 1, name: 'Users' })).toBeInTheDocument();
    expect(screen.getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Name', 'Email', 'Roles', 'Status']);
    const rows = screen.getAllByRole('row').slice(1);
    expect(within(rows[0]!).getAllByRole('cell').map((c) => c.textContent)).toEqual(['Ada Lovelace', 'ada@example.test', 'Buyer and Finance', 'Active']);
    expect(within(rows[1]!).getAllByRole('cell').map((c) => c.textContent)).toEqual(['Me Myself (you)', 'me@example.test', 'No roles', 'Switched off']);
    expect(within(rows[0]!).getByRole('link', { name: 'Ada Lovelace' })).toHaveAttribute('href', `/admin/users/${ID}`);
    expect(screen.queryByText('Most recently deleted first')).toBeNull();
    expect(screen.getByRole('button', { name: 'Archived' })).toHaveAttribute('aria-pressed', 'false');
  });

  it('search: label and placeholder; a case-insensitive email prefix, on the client; nothing is fetched', async () => {
    const calls = stubFetch([]);
    const u = userEvent.setup();
    show();
    const box = screen.getByRole('searchbox', { name: 'Search by email' });
    expect(box).toHaveAttribute('placeholder', 'Start of an email address');
    await u.type(box, 'ADA');
    expect(screen.getAllByRole('row')).toHaveLength(2);
    await waitFor(() => expect(status()).toHaveTextContent('1 person shown. Email starts with “ADA”.'));
    expect(calls).toHaveLength(0);
  });

  it('nothing is announced on first paint, and "0 shown" is never spoken: the filtered-empty heading is', async () => {
    const u = userEvent.setup();
    show();
    expect(status()).toHaveTextContent('');
    await u.type(screen.getByRole('searchbox'), 'zz');
    expect(screen.getByText("No one's email starts with “zz”.", { selector: 'p' })).toBeInTheDocument();
    await waitFor(() => expect(status()).toHaveTextContent("No one's email starts with “zz”."));
    expect(status().textContent).not.toMatch(/0 people shown/);
  });

  it('Clear search empties the box and returns focus to the search field', async () => {
    const u = userEvent.setup();
    show();
    const box = screen.getByRole('searchbox');
    await u.type(box, 'zz');
    await u.click(screen.getByRole('button', { name: 'Clear search' }));
    expect(box).toHaveValue('');
    expect(box).toHaveFocus();
    expect(screen.getAllByRole('row')).toHaveLength(3);
  });
});

describe('/admin/users — the Archived view', () => {
  it('fetches ?archived=true; columns Name · Email · Deleted on; most recently deleted first, with its caption; announced', async () => {
    const calls = stubFetch([json(200, page([OLD, NEW]))]);
    const u = userEvent.setup();
    show();
    await u.click(screen.getByRole('button', { name: 'Archived' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Archived' })).toHaveAttribute('aria-pressed', 'true'));
    expect(calls[0]!.url).toBe('/bff/api/users?archived=true');
    expect(screen.getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Name', 'Email', 'Deleted on']);
    expect(screen.getByText('Most recently deleted first')).toBeInTheDocument();
    const names = screen.getAllByRole('row').slice(1).map((r) => within(r).getAllByRole('cell')[0]!.textContent);
    expect(names).toEqual(['New One', 'Old One']);
    // The Deleted on date is in the session's zone (A-9).
    expect(within(screen.getAllByRole('row')[1]!).getAllByRole('cell')[2]).toHaveTextContent(formatDate(NEW.archived_at!, TZ));
    await waitFor(() => expect(status()).toHaveTextContent('2 people shown. Archived.'));
  });

  it('empty Archived view: "Nobody in this workspace has been deleted."', async () => {
    stubFetch([json(200, page([]))]);
    const u = userEvent.setup();
    show();
    await u.click(screen.getByRole('button', { name: 'Archived' }));
    expect(await screen.findByText('Nobody in this workspace has been deleted.', { selector: 'p' })).toBeInTheDocument();
  });

  it("searched Archived view with no match: \"No archived person's email starts with “{q}”.\"", async () => {
    stubFetch([json(200, page([OLD]))]);
    const u = userEvent.setup();
    show();
    await u.click(screen.getByRole('button', { name: 'Archived' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Archived' })).toHaveAttribute('aria-pressed', 'true'));
    await u.type(screen.getByRole('searchbox'), 'q');
    expect(screen.getByText("No archived person's email starts with “q”.", { selector: 'p' })).toBeInTheDocument();
  });

  it('R2-L2: a failed switch keeps the earlier rows, shows the refetch banner by cause, and the control reverts to the view on screen', async () => {
    stubFetch([json(503, {})]);
    const u = userEvent.setup();
    show();
    await u.click(screen.getByRole('button', { name: 'Archived' }));
    expect(await screen.findByText("The list couldn't be updated, so it still shows the earlier results.")).toBeInTheDocument();
    // The banner shows the heading; the list's one status region speaks it (FE-R3: the banner has no live role).
    expect(screen.getByText('Something went wrong on our side.', { selector: 'p' })).toBeInTheDocument();
    expect(status()).toHaveTextContent('Something went wrong on our side.');
    expect(screen.getByRole('button', { name: 'Archived' })).toHaveAttribute('aria-pressed', 'false');
    expect(screen.getByText('Ada Lovelace')).toBeInTheDocument();
    stubFetch([json(200, page([NEW]))]);
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Archived' })).toHaveAttribute('aria-pressed', 'true'));
    expect(screen.queryByText("The list couldn't be updated, so it still shows the earlier results.")).toBeNull();
  });
});

describe('/admin/users — the first load failed', () => {
  it('region error: R1-1 heading by cause, "The list of people couldn\'t be loaded.", Retry loads the live view', async () => {
    const calls = stubFetch([json(200, page([ADA]))]);
    const u = userEvent.setup();
    show(<UsersList initial={{ kind: 'unreachable' }} viewerId={VIEWER_ID} />);
    expect(screen.getByRole('heading', { name: "We couldn't reach the server." })).toBeInTheDocument();
    expect(screen.getByText("The list of people couldn't be loaded.")).toBeInTheDocument();
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    expect(await screen.findByText('Ada Lovelace')).toBeInTheDocument();
    expect(calls[0]!.url).toBe('/bff/api/users');
  });

  it('a 403 on a later fetch swaps to the ST-4b page (the permission was lost)', async () => {
    const res = new Response(JSON.stringify({ type: 'urn:erp:problem:permission-denied' }), { status: 403, headers: { 'content-type': 'application/problem+json' } });
    stubFetch([res]);
    const u = userEvent.setup();
    show();
    await act(async () => u.click(screen.getByRole('button', { name: 'Archived' })));
    expect(await screen.findByRole('heading', { level: 1, name: "You don't have access to people." })).toBeInTheDocument();
    expect(screen.getByText(/needs the/)).toHaveTextContent('This needs the View people permission. Your workspace administrator can grant it.');
  });
});

describe('/admin/users — the Archived view is in the address (b3b-followup C-4, O-1)', () => {
  beforeEach(() => nav.replace.mockReset());

  it('initialArchived: the first render is the Archived view, with nothing fetched', () => {
    const calls = stubFetch([]);
    show(<UsersList initial={{ kind: 'ok', users: [OLD, NEW] }} initialArchived viewerId={VIEWER_ID} />);
    expect(screen.getByRole('button', { name: 'Archived' })).toHaveAttribute('aria-pressed', 'true');
    expect(screen.getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Name', 'Email', 'Deleted on']);
    expect(calls).toHaveLength(0);
    expect(nav.replace).not.toHaveBeenCalled();
  });

  it('a successful switch replaces the address after the rows arrive; switching back drops the parameter', async () => {
    stubFetch([json(200, page([OLD])), json(200, page([ADA, ME]))]);
    const u = userEvent.setup();
    show();
    await u.click(screen.getByRole('button', { name: 'Archived' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Archived' })).toHaveAttribute('aria-pressed', 'true'));
    expect(nav.replace.mock.calls).toEqual([['/admin/users?archived=true', { scroll: false }]]);
    await u.click(screen.getByRole('button', { name: 'Archived' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Archived' })).toHaveAttribute('aria-pressed', 'false'));
    expect(nav.replace.mock.calls).toEqual([
      ['/admin/users?archived=true', { scroll: false }],
      ['/admin/users', { scroll: false }],
    ]);
  });

  it('a failed switch leaves the address alone', async () => {
    stubFetch([json(503, {})]);
    const u = userEvent.setup();
    show();
    await u.click(screen.getByRole('button', { name: 'Archived' }));
    expect(await screen.findByText("The list couldn't be updated, so it still shows the earlier results.")).toBeInTheDocument();
    expect(nav.replace).not.toHaveBeenCalled();
  });

  it('final-red L-2: after a failed first load of the Archived view, Retry loads the Archived view again', async () => {
    const calls = stubFetch([json(200, page([OLD]))]);
    const u = userEvent.setup();
    show(<UsersList initial={{ kind: 'server-error' }} initialArchived viewerId={VIEWER_ID} />);
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    expect(await screen.findByText('Old One')).toBeInTheDocument();
    expect(calls[0]!.url).toBe('/bff/api/users?archived=true');
    expect(screen.getByRole('button', { name: 'Archived' })).toHaveAttribute('aria-pressed', 'true');
    expect(nav.replace).not.toHaveBeenCalled();
  });
});
