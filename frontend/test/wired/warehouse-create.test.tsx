import { StrictMode, useState } from 'react';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
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

describe('/warehouses/new — the banner (WarehouseCreateForm)', () => {
  it('two out-of-form violations with the same sentence: both shown, no duplicate-key warning', async () => {
    const errors = vi.spyOn(console, 'error').mockImplementation(() => {});
    try {
      stubFetch([problem(422, 'validation-failed', { errors: [{ pointer: '/supplier_id', code: 'x', message: 'Same.' }, { pointer: '', code: 'y', message: 'Same.' }] })]);
      const u = userEvent.setup();
      renderWired(<NewWarehousePage />);
      await fill(u);
      await u.click(submit());
      expect(await screen.findAllByText('Same.')).toHaveLength(2);
      expect(errors.mock.calls.filter((c) => String(c[0]).includes('same key'))).toEqual([]);
    } finally {
      errors.mockRestore();
    }
  });
});

// I-2 follow-up (items-i3-ux.md Revision 1 FINAL F-5 #1): a 422 with an out-of-form sentence AND a field sentence ends
// its banner with this line; no other banner carries it.
const MIXED_LINE = 'Check the marked fields below too.';
const bannerBody = (heading: HTMLElement) => [...heading.parentElement!.children].filter((e) => e !== heading).map((e) => e.textContent);

describe('/warehouses/new (WarehouseCreateForm) — the mixed-422 line (I-2 follow-up)', () => {
  it('a 422 with an out-of-form sentence and a field sentence: the banner ends with "Check the marked fields below too."', async () => {
    stubFetch([problem(422, 'validation-failed', { errors: [{ pointer: '/supplier_id', code: 'x', message: 'Out of form.' }, { pointer: '/name', code: 'blank', message: 'This field may not be blank.' }] })]);
    const u = userEvent.setup();
    renderWired(<NewWarehousePage />);
    await fill(u);
    await u.click(submit());
    const heading = await screen.findByText('Not created');
    expect(bannerBody(heading)).toEqual(['Out of form.', MIXED_LINE]);
  });

  it('a banner-only 422 and a 409 carry no such line', async () => {
    stubFetch([problem(422, 'validation-failed', { errors: [{ pointer: '/supplier_id', code: 'x', message: 'Out of form.' }, { pointer: '', code: 'y', message: 'Also out of form.' }] }), refused('some.rule', 'Refused here.')]);
    const u = userEvent.setup();
    renderWired(<NewWarehousePage />);
    await fill(u);
    await u.click(submit());
    const heading = await screen.findByText('Not created');
    expect(bannerBody(heading)).toEqual(['Out of form.', 'Also out of form.']);
    expect(screen.queryByText(MIXED_LINE)).toBeNull();
    await u.click(submit());
    expect(await screen.findByText('Refused here.')).toBeInTheDocument();
    expect(screen.queryByText(MIXED_LINE)).toBeNull();
  });
});

// suppliers-s2-red.md L-1 (family-wide): `created` calls the page's `onCreated`, which navigates with `router.push`, and the
// form is still on screen until the new page mounts. Here `nav.push` is a mock, so the page never leaves: the window is held
// open. In it the form stays busy, so a press, an Enter or a submit event sends nothing: no second key is spent and no false
// "Not created" appears (the code-taken 409 queued behind the 201 would say it). Every answer other than `created` gives the
// form back, so a form that never clears busy fails here too.
describe('/warehouses/new — after a 201, until the new page takes over (L-1)', () => {
  // A function: a Response body can be read once, so each test needs its own.
  const TAKEN = () => refused('warehouse.code_is_unique_per_workspace', 'Another warehouse already uses this code. Choose a different code.');
  it('Create warehouse stays busy: a second press and an Enter send nothing, and nothing says "Not created"', async () => {
    const calls = stubFetch([json(201, HUB), TAKEN()]);
    const u = userEvent.setup();
    renderWired(<NewWarehousePage />);
    await fill(u);
    await u.click(submit());
    await waitFor(() => expect(nav.push).toHaveBeenCalledWith(`/warehouses/${WID}`));
    expect(submit()).toHaveAttribute('aria-busy', 'true');
    await u.click(submit());
    await u.type(field('Name'), '{Enter}');
    await new Promise((r) => setTimeout(r, 10));
    expect(calls.filter((c) => c.method === 'POST')).toHaveLength(1);
    expect(screen.queryByText('Not created')).toBeNull();
    expect(nav.push).toHaveBeenCalledTimes(1);
  });

  it('the form itself refuses a submit event after a 201, not only the button', async () => {
    const calls = stubFetch([json(201, HUB), TAKEN()]);
    const u = userEvent.setup();
    renderWired(<NewWarehousePage />);
    await fill(u);
    await u.click(submit());
    await waitFor(() => expect(nav.push).toHaveBeenCalledWith(`/warehouses/${WID}`));
    fireEvent.submit(submit().closest('form')!);
    await new Promise((r) => setTimeout(r, 10));
    expect(calls.filter((c) => c.method === 'POST')).toHaveLength(1);
    expect(screen.queryByText('Not created')).toBeNull();
  });

  it('any other answer gives the form back: after a 409 Create warehouse is not busy and a second press sends', async () => {
    const calls = stubFetch([TAKEN(), json(201, HUB)]);
    const u = userEvent.setup();
    renderWired(<NewWarehousePage />);
    await fill(u);
    await u.click(submit());
    expect(await screen.findByText('Not created')).toBeInTheDocument();
    expect(submit()).not.toHaveAttribute('aria-busy');
    await u.click(submit());
    await waitFor(() => expect(nav.push).toHaveBeenCalledWith(`/warehouses/${WID}`));
    expect(calls.filter((c) => c.method === 'POST')).toHaveLength(2);
  });
});
