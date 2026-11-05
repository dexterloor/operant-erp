import { StrictMode, useState } from 'react';
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
const nav = vi.hoisted(() => ({ push: vi.fn(), refresh: vi.fn() }));
vi.mock('next/navigation', () => ({ useRouter: () => ({ push: nav.push, refresh: nav.refresh }) }));
import { leaveArrivalMessage, takeArrivalMessage, useArrivalMessage } from '@/app/(app)/arrivalMessage';
import { NewWarehousePage } from '@/app/(app)/warehouses/new/NewWarehousePage';
import { WarehousePage } from '@/app/(app)/warehouses/[id]/WarehousePage';
import { RouteFocus } from '@/components/shell/RouteFocus';
import type { Warehouse } from '@/lib/api/warehouses';
import { BffProvider } from '@/app/(app)/BffProvider';
import { TooltipProvider } from '@/components/ui/Tooltip';
import { SID, TZ, json, problem, refused, renderWired, stubFetch } from './harness';

// warehouses-ux-copy.md B-4; Revision 7 §2 step 4.3, A-19 items 1, 6, 7 (S-5, R3-6): `/warehouses/new` creates a
// warehouse with a fresh Idempotency-Key per submit, navigates to it, and the new page speaks "{code} was created."
// once, after RouteFocus has moved focus to its h1.

afterEach(() => {
  vi.unstubAllGlobals();
  nav.push.mockReset();
  takeArrivalMessage('');
});

const WID = '6a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';
const HUB: Warehouse = { id: WID, code: 'HUB', name: 'Hub', country_code: 'GB', archived_at: null };
const field = (name: string) => screen.getByRole('textbox', { name });
const submit = () => screen.getByRole('button', { name: 'Create warehouse' });

async function fill(u: ReturnType<typeof userEvent.setup>, code = 'HUB', name = 'Hub', country = 'gb') {
  await u.type(field('Code'), code);
  await u.type(field('Name'), name);
  await u.type(field('Country'), country);
}

describe('/warehouses/new — the form (B-4)', () => {
  it('h1 New warehouse; Code, Name, Country with B-3’s Code and Country hints and no Name hint; Cancel goes to the list', () => {
    renderWired(<NewWarehousePage />);
    expect(screen.getByRole('heading', { level: 1, name: 'New warehouse' })).toBeInTheDocument();
    expect(screen.getAllByRole('textbox').map((i) => i.getAttribute('name'))).toEqual(['code', 'name', 'country_code']);
    expect(field('Code')).toHaveAccessibleDescription('A short code people will recognise, for example MAIN. Each warehouse needs its own.');
    expect(field('Name')).not.toHaveAccessibleDescription();
    expect(field('Country')).toHaveAttribute('maxlength', '2');
    expect(field('Country')).toHaveAttribute('autocomplete', 'off');
    expect(field('Country')).toHaveAttribute('spellcheck', 'false');
    expect(screen.getByRole('link', { name: 'Cancel' })).toHaveAttribute('href', '/warehouses');
  });

  it('201: POSTs all three fields with an Idempotency-Key, leaves "{code} was created." for the new page and navigates there', async () => {
    const calls = stubFetch([json(201, HUB)]);
    const u = userEvent.setup();
    renderWired(<NewWarehousePage />);
    await fill(u);
    await u.click(submit());
    await waitFor(() => expect(nav.push).toHaveBeenCalledWith(`/warehouses/${WID}`));
    expect(nav.push).toHaveBeenCalledTimes(1);
    expect(calls).toHaveLength(1);
    expect(calls[0]!.method).toBe('POST');
    expect(calls[0]!.url).toMatch(/\/bff\/api\/warehouses$/);
    expect(JSON.parse(calls[0]!.body!)).toEqual({ code: 'HUB', name: 'Hub', country_code: 'GB' });
    expect(calls[0]!.headers.get('idempotency-key')).toMatch(/^[0-9a-f-]{36}$/);
    expect(takeArrivalMessage('/warehouses/other')).toBeNull();
  });

  it('the arrival message is keyed to the new warehouse’s address', async () => {
    stubFetch([json(201, HUB)]);
    const u = userEvent.setup();
    renderWired(<NewWarehousePage />);
    await fill(u);
    await u.click(submit());
    await waitFor(() => expect(nav.push).toHaveBeenCalled());
    expect(takeArrivalMessage(`/warehouses/${WID}`)).toBe('HUB was created.');
  });

  it('a fresh key for every submit: W1 after a lost answer is shown as sent under "Not created", the second submit carries a new key', async () => {
    const keys: (string | null)[] = [];
    let n = 0;
    vi.stubGlobal(
      'fetch',
      vi.fn(async (_input: RequestInfo | URL, init: RequestInit = {}) => {
        keys.push(new Headers(init.headers).get('idempotency-key'));
        n += 1;
        if (n === 1) throw new TypeError('offline');
        return refused('warehouse.code_is_unique_per_workspace', 'Another warehouse already uses this code. Choose a different code.');
      }),
    );
    const u = userEvent.setup();
    renderWired(<NewWarehousePage />);
    await fill(u);
    await u.click(submit());
    expect(await screen.findByText("We can't tell whether HUB was created. Check the list of warehouses before you try again.")).toBeInTheDocument();
    expect(screen.getByText("We couldn't reach the server.")).toHaveFocus();
    await u.click(submit());
    expect(await screen.findByText('Another warehouse already uses this code. Choose a different code.')).toBeInTheDocument();
    expect(screen.getByText('Not created')).toHaveFocus();
    expect(keys).toHaveLength(2);
    expect(keys[0]).not.toBeNull();
    expect(keys[0]).not.toBe(keys[1]);
    expect(nav.push).not.toHaveBeenCalled();
  });

  it('a 422 field sentence shows beside its field, not in a banner; focus goes to that field', async () => {
    stubFetch([problem(422, 'validation-failed', { errors: [{ pointer: '/country_code', code: 'unknown_reference', message: "This country isn't supported yet." }] })]);
    const u = userEvent.setup();
    renderWired(<NewWarehousePage />);
    await fill(u, 'HUB', 'Hub', 'zz');
    await u.click(submit());
    await waitFor(() => expect(field('Country')).toHaveAttribute('aria-invalid', 'true'));
    expect(field('Country')).toHaveFocus();
    expect(field('Country')).toHaveAccessibleDescription(expect.stringContaining("This country isn't supported yet."));
    expect(screen.queryByText('Not created')).toBeNull();
  });

  it('a 403 names the Create warehouses permission (B-8)', async () => {
    stubFetch([problem(403, 'permission-denied')]);
    const u = userEvent.setup();
    renderWired(<NewWarehousePage />);
    await fill(u);
    await u.click(submit());
    expect(await screen.findByText(/which you no longer have/)).toHaveTextContent('This needs the Create warehouses permission, which you no longer have. Your workspace administrator can grant it.');
    expect(screen.getByText('Not created')).toHaveFocus();
  });
});

describe('arrivalMessage — keyed by path, cleared on every take (S-5)', () => {
  it('a take for another href returns null and clears', () => {
    leaveArrivalMessage('/warehouses/a', 'A was created.');
    expect(takeArrivalMessage('/warehouses/b')).toBeNull();
    expect(takeArrivalMessage('/warehouses/a')).toBeNull();
  });

  it('a message older than 10 s is not given (RED L-1)', () => {
    vi.useFakeTimers();
    try {
      leaveArrivalMessage('/warehouses/a', 'A was created.');
      vi.advanceTimersByTime(10_001);
      expect(takeArrivalMessage('/warehouses/a')).toBeNull();
      leaveArrivalMessage('/warehouses/a', 'A was created.');
      vi.advanceTimersByTime(9_000);
      expect(takeArrivalMessage('/warehouses/a')).toBe('A was created.');
    } finally {
      vi.useRealTimers();
    }
  });

  it('a second take returns null', () => {
    leaveArrivalMessage('/warehouses/a', 'A was created.');
    expect(takeArrivalMessage('/warehouses/a')).toBe('A was created.');
    expect(takeArrivalMessage('/warehouses/a')).toBeNull();
  });
});

// The ordering after RouteFocus assumes the new page and the new pathname commit together; a `loading.tsx` under
// `(app)` would break it. Its test must then change.
describe.each([
  ['plain', false],
  ['StrictMode', true],
] as const)('the destination page speaks the arrival message once, after the h1 has focus (%s)', (_name, strict) => {
  let go: (path: string) => void = () => {};
  function Frame() {
    const [path, setPath] = useState('/warehouses/new');
    go = setPath;
    return (
      <>
        <main id="main-content">{path === '/warehouses/new' ? <h1 tabIndex={-1}>New warehouse</h1> : <WarehousePage initial={HUB} canEdit canRestore={false} />}</main>
        <RouteFocus routeKey={path} />
      </>
    );
  }
  const mount = () => {
    const tree = (
      <TooltipProvider>
        <BffProvider session={{ sid: SID, tz: TZ }} reload={() => {}}>
          <Frame />
        </BffProvider>
      </TooltipProvider>
    );
    return render(strict ? <StrictMode>{tree}</StrictMode> : tree);
  };

  it('the region holds the sentence once, and the h1 is the active element when it does', async () => {
    mount();
    leaveArrivalMessage(`/warehouses/${WID}`, 'HUB was created.');
    const seen: { text: string; focusedH1: boolean }[] = [];
    const observer = new MutationObserver(() => {
      const r = document.querySelector('section > [role="status"]');
      if (r && r.textContent !== '') seen.push({ text: r.textContent ?? '', focusedH1: document.activeElement?.tagName === 'H1' });
    });
    observer.observe(document.body, { subtree: true, childList: true, characterData: true });
    act(() => go(`/warehouses/${WID}`));
    await waitFor(() => expect(seen.length).toBeGreaterThan(0));
    await new Promise((r) => setTimeout(r, 20));
    observer.disconnect();
    expect(seen).toEqual([{ text: 'HUB was created.', focusedH1: true }]);
    expect(document.activeElement).toBe(screen.getByRole('heading', { level: 1, name: 'HUB — Hub' }));
  });

  it('announce is CALLED after RouteFocus has focused the h1, and once (the write is a queued task, not the effect itself)', async () => {
    const calls: { text: string; active: string | undefined }[] = [];
    const announce = (text: string) => calls.push({ text, active: document.activeElement?.tagName });
    function Dest() {
      useArrivalMessage('/warehouses/x', announce);
      return <h1 tabIndex={-1}>X</h1>;
    }
    let goTo: (p: string) => void = () => {};
    function Shell() {
      const [p, setP] = useState('/warehouses/new');
      goTo = setP;
      return (
        <>
          <main id="main-content">{p === '/warehouses/new' ? <h1 tabIndex={-1}>New warehouse</h1> : <Dest />}</main>
          <RouteFocus routeKey={p} />
        </>
      );
    }
    render(strict ? <StrictMode><Shell /></StrictMode> : <Shell />);
    leaveArrivalMessage('/warehouses/x', 'X was created.');
    act(() => goTo('/warehouses/x'));
    await new Promise((r) => setTimeout(r, 20));
    expect(calls).toEqual([{ text: 'X was created.', active: 'H1' }]);
  });

  it('a page whose address was not the one left says nothing, and the message is gone', async () => {
    mount();
    leaveArrivalMessage('/warehouses/other', 'X was created.');
    act(() => go(`/warehouses/${WID}`));
    await new Promise((r) => setTimeout(r, 20));
    expect(document.querySelector('section > [role="status"]')!.textContent).toBe('');
    expect(takeArrivalMessage('/warehouses/other')).toBeNull();
  });
});
