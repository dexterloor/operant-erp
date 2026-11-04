import { act, fireEvent, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactElement } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

// The users and roles lists keep their view in the address through the App Router; the test supplies one.
const nav = vi.hoisted(() => ({ replace: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ replace: nav.replace }), usePathname: () => '/admin/users' }));
import { UsersList } from '@/app/(app)/admin/users/UsersList';
import { RolesList } from '@/app/(app)/admin/roles/RolesList';
import { SEARCH_SETTLE_MS } from '@/app/(app)/ArchivableList';
import { ROLE_A, VIEWER_ID, json, page, renderWired, role, stubFetch, user } from './harness';

// A Retry after a failed FIRST load (owner-questions-2026-11-03-red.md Q-1 M-3 and X-2):
// - M-3 / principle 6 (b4-architecture.md): the status region is in the DOM, empty, from first render — including while
//   the region error is shown — so the settled sentence is a change to an existing region, not a region born speaking.
// - X-2: the Retry button unmounts with the error, so focus moves to the page h1 (the RolePage / PersonPage re-read
//   precedent), never to <body>; and the h1 takes focus with the rows already on screen, before the sentence is written
//   (FE-D1 order: focus first, then announce).

afterEach(() => vi.unstubAllGlobals());

const status = () => document.querySelector<HTMLElement>('[role="status"]');

function deferred() {
  let resolve!: (r: Response) => void;
  const promise = new Promise<Response>((r) => (resolve = r));
  return { promise, resolve };
}

const CASES: { name: string; ui: ReactElement; h1: string; body: () => unknown; settled: string; row: string }[] = [
  { name: '/admin/users', ui: <UsersList initial={{ kind: 'unreachable' }} viewerId={VIEWER_ID} />, h1: 'Users', body: () => page([user()]), settled: '1 person shown.', row: 'Ada Lovelace' },
  { name: '/admin/roles', ui: <RolesList initial={{ kind: 'server-error' }} />, h1: 'Roles', body: () => page([role(ROLE_A, 'Buyer', [])]), settled: '1 role shown.', row: 'Buyer' },
];

describe.each(CASES)('$name — a successful Retry after a failed first load', ({ ui, h1, body, settled, row }) => {
  it('the status region is mounted empty from first render and the same node speaks after the Retry', async () => {
    const reply = deferred();
    stubFetch([() => reply.promise]);
    const u = userEvent.setup();
    renderWired(ui);
    const region = status();
    expect(region).not.toBeNull();
    expect(region).toHaveTextContent('');
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    expect(status()).toBe(region);
    expect(region).toHaveTextContent('');
    reply.resolve(json(200, body()));
    expect(await screen.findByText(row)).toBeInTheDocument();
    await waitFor(() => expect(region).toHaveTextContent(settled));
    expect(status()).toBe(region);
    expect(document.querySelectorAll('[role="status"]')).toHaveLength(1);
  });

  it('focus moves to the h1, never to <body>', async () => {
    stubFetch([json(200, body())]);
    const u = userEvent.setup();
    renderWired(ui);
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    expect(await screen.findByText(row)).toBeInTheDocument();
    await waitFor(() => expect(document.activeElement).toBe(screen.getByRole('heading', { level: 1, name: h1 })));
    expect(document.activeElement).not.toBe(document.body);
  });

  it('the h1 takes focus with the rows on screen and before the sentence is written', async () => {
    stubFetch([json(200, body())]);
    const u = userEvent.setup();
    renderWired(ui);
    const heading = screen.getByRole('heading', { level: 1, name: h1 });
    const seen: { rows: number; text: string }[] = [];
    heading.addEventListener('focus', () => seen.push({ rows: document.querySelectorAll('tbody tr').length, text: status()?.textContent ?? '' }));
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(status()).toHaveTextContent(settled));
    expect(seen).toEqual([{ rows: 1, text: '' }]);
  });
});

// FE-R3 (fe-r3-ux.md Revision 1; STATES.md focus table, the "couldn't refresh" banner row): a successful banner Retry
// removes the banner with its focused Retry, so focus moves to the Archived toggle (its pressed state names the view now
// shown), and only if focus was lost (inside the removed banner, or on <body>) — never stolen from where the user went
// while waiting (M-3). The settled sentence follows the focus move. On any failed refetch focus does not move, the
// failure heading is written to the list's one status region (principle 6: the banner has no live role of its own), the
// region's text changes on every failure even with the same cause (it is blanked while the refetch is in flight), and the
// settled "shown" sentence is never left in the region after a failure (defect A, widened by M-2).

/** The list's own status region: the direct child of the list section, never the first [role="status"] on the page (RED L-2). */
const listRegion = () => {
  const region = screen.getByRole('heading', { level: 1 }).closest('section')!.querySelector<HTMLElement>(':scope > [role="status"]');
  expect(region).not.toBeNull();
  return region!;
};

/** Every text the region has held, in order (a MutationObserver: the region's text is what a screen reader is given). */
function textsOf(region: HTMLElement) {
  const texts: string[] = [region.textContent ?? ''];
  const obs = new MutationObserver(() => {
    const t = region.textContent ?? '';
    if (t !== texts[texts.length - 1]) texts.push(t);
  });
  obs.observe(region, { childList: true, characterData: true, subtree: true });
  return { texts, stop: () => obs.disconnect() };
}

const BANNER_BODY = "The list couldn't be updated, so it still shows the earlier results.";
const WENT_WRONG_TEXT = 'Something went wrong on our side.';

const BANNER_CASES: { name: string; ui: () => ReactElement; liveRow: string; archivedRow: string; archivedBody: () => unknown; settledArchived: string; settledLive: string; settledSearch: string }[] = [
  {
    name: '/admin/users',
    ui: () => <UsersList initial={{ kind: 'ok', users: [user()] }} viewerId={VIEWER_ID} />,
    liveRow: 'Ada Lovelace',
    archivedRow: 'Old One',
    archivedBody: () => page([user({ id: 'a1', email: 'old@example.test', display_name: 'Old One', archived_at: '2026-01-02T10:00:00Z' })]),
    settledArchived: '1 person shown. Archived.',
    settledLive: '1 person shown.',
    settledSearch: '1 person shown. Email starts with “Ad”.',
  },
  {
    name: '/admin/roles',
    ui: () => <RolesList initial={{ kind: 'ok', roles: [role(ROLE_A, 'Buyer', [])] }} />,
    liveRow: 'Buyer',
    archivedRow: 'Old Clerk',
    archivedBody: () => page([{ ...role('5a216e4d-8f9e-4b3d-8c7a-0fb3c4d56e7f', 'Old Clerk', []), archived_at: '2026-01-02T10:00:00Z' }]),
    settledArchived: '1 role shown. Archived.',
    settledLive: '1 role shown.',
    settledSearch: '1 role shown. Name starts with “Bu”.',
  },
];

describe.each(BANNER_CASES)('$name — the "couldn\'t refresh" banner (FE-R3)', ({ ui, liveRow, archivedRow, archivedBody, settledArchived, settledLive, settledSearch }) => {
  const toggle = () => screen.getByRole('button', { name: 'Archived' });
  const retry = () => screen.getByRole('button', { name: 'Retry' });
  const firstRow = () => document.querySelector('tbody tr')?.textContent ?? '';

  async function failedSwitch(u: ReturnType<typeof userEvent.setup>) {
    await u.click(toggle());
    expect(await screen.findByText(BANNER_BODY)).toBeInTheDocument();
    expect(toggle()).toHaveAttribute('aria-pressed', 'false');
    // Principle 6: the list's one region speaks the failure; the banner is not a second, newborn live region.
    expect(document.querySelectorAll('[role="status"], [role="alert"], [aria-live]')).toHaveLength(1);
    expect(listRegion()).toHaveTextContent(WENT_WRONG_TEXT);
  }

  it('1. a successful banner Retry moves focus to the Archived toggle, pressed, never to <body>', async () => {
    stubFetch([json(503, {}), json(200, archivedBody())]);
    const u = userEvent.setup();
    renderWired(ui());
    await failedSwitch(u);
    await u.click(retry());
    expect(await screen.findByText(archivedRow)).toBeInTheDocument();
    expect(screen.queryByText(BANNER_BODY)).toBeNull();
    await waitFor(() => expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Archived', pressed: true })));
    expect(document.activeElement).not.toBe(document.body);
  });

  it('2. the toggle takes focus once, pressed, with the archived rows on screen, before the sentence is written', async () => {
    stubFetch([json(503, {}), json(200, archivedBody())]);
    const u = userEvent.setup();
    renderWired(ui());
    expect(firstRow()).toContain(liveRow);
    await failedSwitch(u);
    const region = listRegion();
    const seen: { pressed: string | null; firstRow: string; text: string }[] = [];
    toggle().addEventListener('focus', (e) => seen.push({ pressed: (e.target as HTMLElement).getAttribute('aria-pressed'), firstRow: firstRow(), text: region.textContent ?? '' }));
    await u.click(retry());
    await waitFor(() => expect(region).toHaveTextContent(settledArchived));
    expect(seen).toEqual([{ pressed: 'true', firstRow: expect.stringContaining(archivedRow), text: '' }]);
    expect(seen[0]!.firstRow).not.toContain(liveRow);
  });

  it('3. a repeat failure from the Retry keeps focus on the same Retry node, speaks the heading again, never the settled sentence', async () => {
    const second = deferred();
    stubFetch([json(503, {}), () => second.promise]);
    const u = userEvent.setup();
    renderWired(ui());
    await u.type(screen.getByRole('searchbox'), liveRow.slice(0, 2));
    await waitFor(() => expect(listRegion().textContent).toMatch(/shown/));
    await failedSwitch(u);
    const region = listRegion();
    await waitFor(() => expect(region).toHaveTextContent(WENT_WRONG_TEXT));
    const log = textsOf(region);
    const button = retry();
    await u.click(button);
    second.resolve(json(503, {}));
    await waitFor(() => expect(log.texts.filter((t) => t === WENT_WRONG_TEXT).length).toBeGreaterThan(1));
    log.stop();
    // pin, green today: the banner is not re-mounted, so the Retry the user pressed keeps focus.
    expect(retry()).toBe(button);
    expect(document.activeElement).toBe(button);
    // the failure was a text change of the region: blanked while in flight, then the heading again.
    expect(log.texts).toEqual([WENT_WRONG_TEXT, '', WENT_WRONG_TEXT]);
    expect(region.textContent).not.toMatch(/shown/);
  });

  it('3a. a repeat failure started from the toggle while the banner is up speaks the heading again, never the settled sentence', async () => {
    const second = deferred();
    stubFetch([json(503, {}), () => second.promise]);
    const u = userEvent.setup();
    renderWired(ui());
    await u.type(screen.getByRole('searchbox'), liveRow.slice(0, 2));
    await waitFor(() => expect(listRegion().textContent).toMatch(/shown/));
    await failedSwitch(u);
    const region = listRegion();
    await waitFor(() => expect(region).toHaveTextContent(WENT_WRONG_TEXT));
    const log = textsOf(region);
    const button = toggle();
    await u.click(button);
    second.resolve(json(503, {}));
    await waitFor(() => expect(log.texts.filter((t) => t === WENT_WRONG_TEXT).length).toBeGreaterThan(1));
    log.stop();
    expect(document.activeElement).toBe(button);
    expect(log.texts).toEqual([WENT_WRONG_TEXT, '', WENT_WRONG_TEXT]);
    expect(region.textContent).not.toMatch(/shown/);
  });

  it('3b. a first failed switch, after a good switch, never leaves the settled sentence in the region', async () => {
    stubFetch([json(200, archivedBody()), json(503, {})]);
    const u = userEvent.setup();
    renderWired(ui());
    const region = listRegion();
    await u.click(toggle());
    await waitFor(() => expect(region).toHaveTextContent(settledArchived));
    const log = textsOf(region);
    await u.click(toggle());
    expect(await screen.findByText(BANNER_BODY)).toBeInTheDocument();
    await waitFor(() => expect(region).toHaveTextContent(WENT_WRONG_TEXT));
    log.stop();
    expect(log.texts.slice(1).some((t) => /shown/.test(t))).toBe(false);
    expect(log.texts).not.toContain(settledLive);
  });

  it('5. searching after a failed switch never speaks the unfiltered settled sentence; the region goes from the heading straight to the search sentence', async () => {
    stubFetch([json(503, {})]);
    const u = userEvent.setup();
    renderWired(ui());
    await failedSwitch(u);
    const region = listRegion();
    const log = textsOf(region);
    await u.type(screen.getByRole('searchbox'), liveRow.slice(0, 2));
    await waitFor(() => expect(region.textContent).toMatch(/shown/));
    log.stop();
    expect(log.texts).toEqual([WENT_WRONG_TEXT, settledSearch]);
    expect(log.texts).not.toContain(settledLive);
  });

  it('7. a search typed just before a failed switch does not clear the failure heading when it settles afterwards', async () => {
    stubFetch([json(503, {})]);
    const u = userEvent.setup();
    renderWired(ui());
    const region = listRegion();
    const log = textsOf(region);
    // Type, then fail the switch while that search's settle timer is still pending.
    await u.type(screen.getByRole('searchbox'), liveRow.slice(0, 2));
    await u.click(toggle());
    expect(await screen.findByText(BANNER_BODY)).toBeInTheDocument();
    await waitFor(() => expect(region).toHaveTextContent(WENT_WRONG_TEXT));
    // The failure arrived while the search's settle timer was still pending.
    expect(log.texts).not.toContain(settledSearch);
    // Let the pending timer fire: the search settled before the failure, so the heading stays.
    await act(() => new Promise((r) => setTimeout(r, SEARCH_SETTLE_MS + 200)));
    log.stop();
    expect(region).toHaveTextContent(WENT_WRONG_TEXT);
    // From the failure on, the region holds only the heading. (Before it, the first keystroke's unsettled sentence is
    // the list's existing search behaviour, not an after-failure sentence.)
    expect(log.texts.slice(log.texts.indexOf(WENT_WRONG_TEXT))).toEqual([WENT_WRONG_TEXT]);
  });

  it('6. a successful press of the Archived toggle never moves focus, even when the browser left focus on <body> (ST-1a)', async () => {
    stubFetch([json(200, archivedBody())]);
    renderWired(ui());
    expect(document.activeElement).toBe(document.body);
    // As Safari and Firefox on macOS do: a mouse click on a button does not focus it.
    fireEvent.click(toggle());
    expect(await screen.findByText(archivedRow)).toBeInTheDocument();
    await waitFor(() => expect(listRegion()).toHaveTextContent(settledArchived));
    expect(document.activeElement).toBe(document.body);
  });

  it('4. focus moved to the search box while the Retry waits is not stolen on success; the sentence is still written', async () => {
    const second = deferred();
    stubFetch([json(503, {}), () => second.promise]);
    const u = userEvent.setup();
    renderWired(ui());
    await failedSwitch(u);
    await u.click(retry());
    const box = screen.getByRole('searchbox');
    box.focus();
    second.resolve(json(200, archivedBody()));
    expect(await screen.findByText(archivedRow)).toBeInTheDocument();
    await waitFor(() => expect(listRegion()).toHaveTextContent(settledArchived));
    expect(document.activeElement).toBe(box);
  });
});

describe.each(CASES)('$name — a first-load Retry does not steal focus (FE-R3 M-3, case (a) twin)', ({ ui, body, settled, row }) => {
  it('focus moved to the page while the Retry waits stays there on success; the sentence is still written', async () => {
    const reply = deferred();
    stubFetch([() => reply.promise]);
    const u = userEvent.setup();
    renderWired(
      <>
        <a href="/elsewhere">Elsewhere</a>
        {ui}
      </>,
    );
    await u.click(screen.getByRole('button', { name: 'Retry' }));
    const link = screen.getByRole('link', { name: 'Elsewhere' });
    link.focus();
    reply.resolve(json(200, body()));
    expect(await screen.findByText(row)).toBeInTheDocument();
    await waitFor(() => expect(listRegion()).toHaveTextContent(settled));
    expect(document.activeElement).toBe(link);
  });
});
