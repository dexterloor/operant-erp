import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactElement } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

// The users and roles lists keep their view in the address through the App Router; the test supplies one.
const nav = vi.hoisted(() => ({ replace: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ replace: nav.replace }), usePathname: () => '/admin/users' }));
import { UsersList } from '@/app/(app)/admin/users/UsersList';
import { RolesList } from '@/app/(app)/admin/roles/RolesList';
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
