import { act, render, screen } from '@testing-library/react';
import { useEffect } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { BffProvider, useApiCaller } from '@/app/(app)/BffProvider';
import { resetDocumentArrivalForTest } from '@/components/shell/DocumentArrival';

// ux-copy-b3b.md C3-1: the fixed page on a second session-changed (R2-4), rendered by the provider; and the provider's
// one-transport-per-document contract. Strings typed here independently of src/copy.
const H1 = 'This tab is out of date.';
const S1 = "A newer sign-in in this browser replaced the one this tab was using, so your last action wasn't sent.";
const S2 = 'If you sign in again here, other tabs in this browser will switch to that sign-in.';
const ID = '0b7c1f9e-3f4a-4c8e-9d2b-5a6e7f8091a2';

const changed = () => new Response(JSON.stringify({ type: 'urn:erp:bff:session-changed' }), { status: 409, headers: { 'content-type': 'application/problem+json' } });
const op = { method: 'GET', template: '/api/v1/users/{id}', pathParams: ['id'], idempotencyKeyRequired: false, permission: { kind: 'none' } } as const;

let caller: ReturnType<typeof useApiCaller> | null = null;
function Probe() {
  const c = useApiCaller();
  useEffect(() => {
    caller = c;
  }, [c]);
  return <p>page content</p>;
}

afterEach(() => {
  vi.unstubAllGlobals();
  window.sessionStorage.clear();
  resetDocumentArrivalForTest();
  caller = null;
});

describe('BffProvider — the session-changed fixed page (C3-1)', () => {
  it('first 409: a silent reload and nothing shown; two 409s while the reload is pending show nothing', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => changed()));
    const reload = vi.fn();
    render(
      <BffProvider session={{ sid: 's1', tz: 'UTC' }} reload={reload}>
        <Probe />
      </BffProvider>,
    );
    await act(async () => {
      await Promise.all([caller!.operation(op, { id: ID }), caller!.operation(op, { id: ID })]);
    });
    expect(reload).toHaveBeenCalledTimes(1);
    expect(screen.queryByText(H1)).toBeNull();
    expect(screen.getByText('page content')).toBeInTheDocument();
  });

  it('a 409 in the document after that reload replaces the page with the four literals; h1 focused; no alert; link to /login', async () => {
    window.sessionStorage.setItem('erp.session-changed-reload', '1');
    vi.stubGlobal('fetch', vi.fn(async () => changed()));
    const reload = vi.fn();
    render(
      <BffProvider session={{ sid: 's2', tz: 'UTC' }} reload={reload}>
        <Probe />
      </BffProvider>,
    );
    await act(async () => {
      await caller!.operation(op, { id: ID });
    });
    expect(reload).not.toHaveBeenCalled();
    const h1 = screen.getByRole('heading', { level: 1, name: H1 });
    expect(h1).toHaveFocus();
    expect(screen.getByText(S1)).toBeInTheDocument();
    expect(screen.getByText(S2)).toBeInTheDocument();
    const link = screen.getByRole('link', { name: 'Sign in again' });
    expect(link).toHaveAttribute('href', '/login');
    expect(screen.queryByRole('alert')).toBeNull();
    expect(screen.queryByText('page content')).toBeNull();
    expect(document.title).toBe('This tab is out of date · ERP');
  });

  it('one caller per document: re-rendering the provider keeps the same caller', () => {
    const { rerender } = render(
      <BffProvider session={{ sid: 's1', tz: 'UTC' }}>
        <Probe />
      </BffProvider>,
    );
    const first = caller;
    rerender(
      <BffProvider session={{ sid: 's1', tz: 'UTC' }}>
        <Probe />
      </BffProvider>,
    );
    expect(caller).toBe(first);
  });

  it('useApiCaller outside the provider is a programming error', () => {
    vi.spyOn(console, 'error').mockImplementation(() => {});
    expect(() => render(<Probe />)).toThrow();
  });
});
