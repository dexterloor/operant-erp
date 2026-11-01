import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
// R-13 (restore-role-design.md F-1 L-13, branch R): PersonPage refreshes the router cache after a restore; `refresh` is a spy.
const nav = vi.hoisted(() => ({ refresh: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ refresh: nav.refresh }) }));
import { PersonPage } from '@/app/(app)/admin/users/[id]/PersonPage';
import { formatDate } from '@/lib/format/dates';
import { ID, TZ, VIEWER_ID, empty, json, problem, refused, renderWired, stubFetch, user } from './harness';

// ux-copy-b3b.md C5-2 and b3b-design.md §9.1 / §10: the person page wires Delete / Restore person through the BFF; a
// delete whose re-read fails shows the after-message AND the region error (focus on the region error's heading); a
// GetUser re-read that answers 403 swaps to ST-4b, 404 to ST-5 with no other sentence.

afterEach(() => {
  vi.unstubAllGlobals();
  nav.refresh.mockReset();
});

const DELETED = user({ archived_at: '2026-10-28T10:00:00Z' });
const NO_ROLES = { delete: true, assign: false, view: false, confirm: false };
const show = ({ person = user(), canDelete = true, viewerId = VIEWER_ID }: { person?: ReturnType<typeof user>; canDelete?: boolean; viewerId?: string } = {}) =>
  renderWired(<PersonPage initial={{ person, access: null, catalogue: null }} viewerId={viewerId} gates={{ ...NO_ROLES, delete: canDelete }} />);

async function deleteIt(u: ReturnType<typeof userEvent.setup>) {
  await u.click(screen.getByRole('button', { name: 'Delete person' }));
  await u.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Delete person' }));
}


describe('/admin/users/[id] — wired', () => {
  it('h1 is the served display name; "(you)" on your own page; no actions without the delete gate', () => {
    show({ canDelete: false });
    expect(screen.getByRole('heading', { level: 1, name: 'Ada Lovelace' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Delete person' })).toBeNull();
  });

  it('own page: "{name} (you)"', () => {
    show({ viewerId: ID });
    expect(screen.getByRole('heading', { level: 1, name: 'Ada Lovelace (you)' })).toBeInTheDocument();
  });

  it('Delete → DELETE then GetUser; the page becomes the deleted person\'s, with the chip, banner and after-message', async () => {
    const calls = stubFetch([empty(204), json(200, DELETED)]);
    const u = userEvent.setup();
    show();
    await deleteIt(u);
    expect(await screen.findByText("Ada Lovelace was deleted. They're now in the archived people.")).toBeInTheDocument();
    expect(screen.getByText('Deleted')).toBeInTheDocument();
    expect(screen.getByText(/This person was deleted on/)).toBeInTheDocument();
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(calls.map((c) => `${c.method} ${c.url}`)).toEqual([`DELETE /bff/api/users/${ID}`, `GET /bff/api/users/${ID}`]);
  });

  it('deleted, but the re-read failed: the after-message and the region error (one body), focus on its heading; Retry re-reads', async () => {
    stubFetch([empty(204), json(500, {})]);
    const u = userEvent.setup();
    show();
    await deleteIt(u);
    expect(await screen.findByText("Ada Lovelace was deleted. They're now in the archived people.")).toBeInTheDocument();
    const heading = await screen.findByRole('heading', { name: 'Something went wrong on our side.' });
    expect(screen.getByText("We couldn't load this person's details again, so what's shown may be out of date.")).toBeInTheDocument();
    await waitFor(() => expect(heading).toHaveFocus());
    // Focus must stay there once the dialog's own close timers have run, not merely arrive (b3b-dialog-focus-red L-3).
    await new Promise((r) => setTimeout(r, 50));
    expect(heading).toHaveFocus();
    stubFetch([json(200, DELETED)]);
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(screen.queryByText("We couldn't load this person's details again, so what's shown may be out of date.")).toBeNull());
    expect(screen.getByText(/This person was deleted on/)).toBeInTheDocument();
  });

  it('a refusal renders inside the dialog under "Not deleted", the server sentence verbatim', async () => {
    stubFetch([refused('app_user.last_owner', 'They are the last owner, so they cannot be deleted.')]);
    const u = userEvent.setup();
    show();
    await deleteIt(u);
    expect(await within(screen.getByRole('dialog')).findByText('They are the last owner, so they cannot be deleted.')).toBeInTheDocument();
    expect(within(screen.getByRole('dialog')).getByText('Not deleted')).toBeInTheDocument();
  });

  it('a GetUser re-read that answers 403 swaps the page to ST-4b', async () => {
    stubFetch([empty(204), problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    show();
    await deleteIt(u);
    expect(await screen.findByRole('heading', { level: 1, name: "You don't have access to people." })).toBeInTheDocument();
  });

  it('a GetUser re-read that answers 404 swaps to ST-5, with no other sentence', async () => {
    stubFetch([empty(204), problem(404, 'not-found')]);
    const u = userEvent.setup();
    show();
    await deleteIt(u);
    expect(await screen.findByRole('heading', { level: 1, name: "We couldn't find that." })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Back to the list' })).toHaveAttribute('href', '/admin/users');
    expect(screen.queryByText(/was deleted/)).toBeNull();
  });

  it('dates are in the zone the person signed in from (the session tz), never the browser default (A-9)', () => {
    const at = '2026-10-28T12:00:00Z';
    show({ person: user({ archived_at: at }) });
    const browser = formatDate(at, Intl.DateTimeFormat().resolvedOptions().timeZone);
    expect(formatDate(at, TZ)).not.toBe(browser);
    expect(screen.getByText(/This person was deleted on/)).toHaveTextContent(`This person was deleted on ${formatDate(at, TZ)}.`);
  });

  it('Restore → POST …/restoration; the served person replaces the page', async () => {
    const calls = stubFetch([json(200, user())]);
    const u = userEvent.setup();
    show({ person: DELETED });
    await u.click(screen.getByRole('button', { name: 'Restore person' }));
    await u.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Restore person' }));
    expect(await screen.findByText('Ada Lovelace was restored. Give them a role so they can work.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Delete person' })).toBeInTheDocument();
    expect(calls.map((c) => `${c.method} ${c.url}`)).toEqual([`POST /bff/api/users/${ID}/restoration`]);
  });

  it('R-13: after a restore, refresh once, with the message already on screen; never on a refusal, a failure or a delete', async () => {
    const u = userEvent.setup();
    const restore = async () => {
      await u.click(screen.getByRole('button', { name: 'Restore person' }));
      await u.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Restore person' }));
    };
    let atRefresh: string[] = [];
    nav.refresh.mockImplementation(() => {
      atRefresh.push(screen.queryByText(/^Ada Lovelace was restored\./)?.textContent ?? '(no message)');
    });
    for (const [served, message] of [
      [user(), 'Ada Lovelace was restored. Give them a role so they can work.'],
      [user({ is_active: false }), 'Ada Lovelace was restored.'],
    ] as const) {
      stubFetch([json(200, served)]);
      const v = show({ person: DELETED });
      await restore();
      await screen.findByText(message);
      await waitFor(() => expect(nav.refresh).toHaveBeenCalled());
      // Let any later effect or timer run: still exactly one call, made after the message was committed.
      await new Promise((r) => setTimeout(r, 20));
      expect(nav.refresh).toHaveBeenCalledTimes(1);
      expect(atRefresh).toEqual([message]);
      v.unmount();
      nav.refresh.mockClear();
      atRefresh = [];
    }
    for (const reply of [
      refused('app_user.planted', 'X-planted restore sentence'),
      problem(403, 'permission-denied'),
      problem(404, 'not-found'),
      json(503, {}),
      json(500, {}),
      () => {
        throw new TypeError('fetch failed');
      },
    ]) {
      stubFetch([reply]);
      const v = show({ person: DELETED });
      await restore();
      await within(screen.getByRole('dialog')).findByText(/Not restored|We couldn't reach|Something went wrong/);
      await new Promise((r) => setTimeout(r, 20));
      v.unmount();
    }
    stubFetch([empty(204), json(200, DELETED)]);
    const v = show();
    await deleteIt(u);
    await screen.findByText("Ada Lovelace was deleted. They're now in the archived people.");
    await new Promise((r) => setTimeout(r, 20));
    v.unmount();
    expect(nav.refresh).not.toHaveBeenCalled();
  });
});
