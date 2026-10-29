import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { BffProvider } from '@/app/(app)/BffProvider';
import { ChangePasswordRoute } from '@/app/(app)/account/password/ChangePasswordRoute';
import Page from '@/app/(app)/account/password/page';

// The change-password page inside the signed-in frame (c10-design.md FINAL B.3, B.7 R-3, R-4, F-5; b4-architecture.md
// Revision 6 A-12, A-14): the call carries the frame's sid; a 401 goes to sign-in with the session-ended notice; a 409
// reloads once; the page itself renders no <main> (the frame's AppFrame provides it).

type Call = [string, RequestInit];
function stub(answer: () => Response) {
  const calls: Call[] = [];
  vi.stubGlobal(
    'fetch',
    vi.fn(async (u: string, i: RequestInit) => {
      calls.push([u, i]);
      return answer();
    }),
  );
  return calls;
}
const problem = (status: number, type: string) => new Response(JSON.stringify({ type, status }), { status, headers: { 'content-type': 'application/problem+json' } });

afterEach(() => {
  vi.unstubAllGlobals();
});

async function submit() {
  fireEvent.change(screen.getByLabelText('Current password'), { target: { value: 'old password here' } });
  fireEvent.change(screen.getByLabelText('New password'), { target: { value: 'a brand new password' } });
  await act(async () => {
    fireEvent.click(screen.getByRole('button', { name: 'Change password' }));
  });
}

describe('R-3 — the request carries the sid of the frame the page was rendered in', () => {
  it('BffProvider {sid: S-1, tz: UTC} → X-ERP-Session: S-1 on POST /bff/me/password', async () => {
    const calls = stub(() => problem(422, 'urn:erp:problem:validation-failed'));
    render(
      <BffProvider session={{ sid: 'S-1', tz: 'UTC' }}>
        <ChangePasswordRoute navigate={vi.fn()} reload={vi.fn()} />
      </BffProvider>,
    );
    await submit();
    expect(calls).toHaveLength(1);
    const [url, init] = calls[0]!;
    expect(url).toBe('/bff/me/password');
    expect(new Headers(init.headers).get('x-erp-session')).toBe('S-1');
  });

  it('outside the provider the route cannot render (no sid to send)', () => {
    const err = vi.spyOn(console, 'error').mockImplementation(() => {});
    expect(() => render(<ChangePasswordRoute />)).toThrow(/outside BffProvider/);
    err.mockRestore();
  });
});

describe('F-5 / F-4 — the route decides what a 401 and a 409 do', () => {
  it('401 session-ended → a full load of /login?reason=session_ended', async () => {
    stub(() => problem(401, 'urn:erp:bff:session-ended'));
    const navigate = vi.fn();
    const reload = vi.fn();
    render(
      <BffProvider session={{ sid: 'S-1', tz: 'UTC' }}>
        <ChangePasswordRoute navigate={navigate} reload={reload} />
      </BffProvider>,
    );
    await submit();
    expect(navigate).toHaveBeenCalledWith('/login?reason=session_ended');
    expect(navigate).toHaveBeenCalledTimes(1);
    expect(reload).not.toHaveBeenCalled();
  });

  it('409 session-changed → one reload, no navigation', async () => {
    stub(() => problem(409, 'urn:erp:bff:session-changed'));
    const navigate = vi.fn();
    const reload = vi.fn();
    render(
      <BffProvider session={{ sid: 'S-1', tz: 'UTC' }}>
        <ChangePasswordRoute navigate={navigate} reload={reload} />
      </BffProvider>,
    );
    await submit();
    expect(reload).toHaveBeenCalledTimes(1);
    expect(navigate).not.toHaveBeenCalled();
  });
});

describe('R-4 — the moved page renders no <main> of its own (L-7)', () => {
  it('page.tsx inside the provider: zero <main>, and the form is there', () => {
    const { container } = render(<BffProvider session={{ sid: 'S-1', tz: 'UTC' }}>{Page()}</BffProvider>);
    expect(container.querySelectorAll('main')).toHaveLength(0);
    expect(screen.getByRole('heading', { level: 1, name: 'Change password' })).toBeInTheDocument();
  });
});
