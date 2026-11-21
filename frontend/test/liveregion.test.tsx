import { readdirSync, readFileSync, statSync } from 'node:fs';
import path from 'node:path';
import { StrictMode, useState } from 'react';
import ts from 'typescript';
import { act, render, renderHook, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { LiveRegion, useLiveRegion, useWaitStatus, WAIT_STATUS_DELAY_MS } from '@/components/ui/LiveRegion';

// A surface that swaps its branch on success (the people page shape, RED M-6): the region sits ABOVE the branch.
function Surface() {
  const live = useLiveRegion();
  const [done, setDone] = useState(false);
  return (
    <section>
      <LiveRegion message={live.message} />
      {done ? (
        <p>Archived view</p>
      ) : (
        <button
          type="button"
          onClick={() => {
            setDone(true);
            live.announce('Ana was deleted.');
          }}
        >
          Delete
        </button>
      )}
      <button type="button" onClick={live.clear}>
        Dismiss message
      </button>
    </section>
  );
}

describe('LiveRegion (R4-4 L-2, RED M-6)', () => {
  it('is mounted empty at first render and the same node carries every later message', async () => {
    const user = userEvent.setup();
    render(<Surface />);
    const region = screen.getByRole('status');
    expect(region).toBeEmptyDOMElement();
    expect(region).toHaveAttribute('aria-live', 'polite');
    await user.click(screen.getByRole('button', { name: 'Delete' }));
    expect(screen.getByRole('status')).toBe(region);
    expect(region).toHaveTextContent('Ana was deleted.');
    await user.click(screen.getByRole('button', { name: 'Dismiss message' }));
    expect(screen.getByRole('status')).toBe(region);
    expect(region).toBeEmptyDOMElement();
  });

  it('under StrictMode: still one region, empty at first, announced once', async () => {
    const user = userEvent.setup();
    render(
      <StrictMode>
        <Surface />
      </StrictMode>,
    );
    expect(screen.getAllByRole('status')).toHaveLength(1);
    const region = screen.getByRole('status');
    expect(region).toBeEmptyDOMElement();
    await user.click(screen.getByRole('button', { name: 'Delete' }));
    expect(screen.getByRole('status')).toBe(region);
    expect(region.textContent).toBe('Ana was deleted.');
  });

  it('announce replaces the text; announce and clear are stable across renders', () => {
    const { result, rerender } = renderHook(() => useLiveRegion());
    const { announce, clear } = result.current;
    act(() => result.current.announce('One'));
    act(() => result.current.announce('Two'));
    expect(result.current.message).toBe('Two');
    rerender();
    expect(result.current.announce).toBe(announce);
    expect(result.current.clear).toBe(clear);
    act(() => result.current.clear());
    expect(result.current.message).toBe('');
    render(<LiveRegion message="Two" visuallyHidden />);
    expect(screen.getByRole('status')).toHaveClass('visually-hidden');
  });
});

// FE-R6 (fe-r6-saving-status-design.md Revision 1 — FINAL commit B, B-1 and B-5; final RED B-1): the exact 1499/1500 ms
// boundaries live here only — renderHook, fake timers, no user-event. The wired cases (item-page.test.tsx) use margins.
describe('useWaitStatus (FE-R6)', () => {
  beforeEach(() => vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] }));
  afterEach(() => vi.useRealTimers());
  const T = 'Saving…';
  const hook = (busy = false) => renderHook(({ busy }: { busy: boolean }) => useWaitStatus(busy, T), { initialProps: { busy } });
  const at = (ms: number) =>
    act(() => {
      vi.advanceTimersByTime(ms);
    });

  it('the delay is 1500 ms (M-5)', () => {
    expect(WAIT_STATUS_DELAY_MS).toBe(1500);
  });

  it('empty while not busy, and still empty at 1499 ms of busy', () => {
    const { result, rerender } = hook();
    at(5000);
    expect(result.current).toBe('');
    rerender({ busy: true });
    at(1499);
    expect(result.current).toBe('');
  });

  it('the text at 1500 ms of busy', () => {
    const { result, rerender } = hook();
    rerender({ busy: true });
    at(1500);
    expect(result.current).toBe(T);
  });

  // M8 (timer armed at mount): a page open for 5 s before the press would say the text at once.
  it('the count starts at the press, not at mount: idle 5 s, then busy, still empty at 0 and 1499 ms', () => {
    const { result, rerender } = hook();
    at(5000);
    rerender({ busy: true });
    expect(result.current).toBe('');
    at(1499);
    expect(result.current).toBe('');
    at(1);
    expect(result.current).toBe(T);
  });

  it('cleared when busy ends before the delay, and nothing is written later', () => {
    const { result, rerender } = hook();
    rerender({ busy: true });
    at(1000);
    rerender({ busy: false });
    expect(result.current).toBe('');
    at(5000);
    expect(result.current).toBe('');
    expect(vi.getTimerCount()).toBe(0);
  });

  it('cleared when busy ends after the delay', () => {
    const { result, rerender } = hook();
    rerender({ busy: true });
    at(1500);
    expect(result.current).toBe(T);
    rerender({ busy: false });
    expect(result.current).toBe('');
  });

  it('busy 600, idle, busy 600: empty (each busy period counts from its own start)', () => {
    const { result, rerender } = hook();
    rerender({ busy: true });
    at(600);
    rerender({ busy: false });
    rerender({ busy: true });
    at(600);
    expect(result.current).toBe('');
  });

  // RED M-3 / M11 (waited not reset in cleanup): the second period would start already saying the text.
  it('busy 1600, idle, busy: empty at 0 and 1499 ms of the second period, the text at 1500 ms', () => {
    const { result, rerender } = hook();
    rerender({ busy: true });
    at(1600);
    expect(result.current).toBe(T);
    rerender({ busy: false });
    rerender({ busy: true });
    expect(result.current).toBe('');
    at(1499);
    expect(result.current).toBe('');
    at(1);
    expect(result.current).toBe(T);
  });

  // Revision 1 B-1 (mandatory): the value is computed in render, so the render in which busy turns false never carries
  // the text, even before the effect cleanup has reset it (M15).
  it('no render with busy false returns the text', () => {
    const seen: [boolean, string][] = [];
    const { rerender } = renderHook(
      ({ busy }: { busy: boolean }) => {
        const v = useWaitStatus(busy, T);
        seen.push([busy, v]);
        return v;
      },
      { initialProps: { busy: false } },
    );
    rerender({ busy: true });
    at(1500);
    rerender({ busy: false });
    expect(seen.some(([b, v]) => b && v === T)).toBe(true);
    expect(seen.filter(([b, v]) => !b && v !== '')).toEqual([]);
  });

  // RED M-2 / M9: React 19 gives no warning for a set after unmount, so the timer itself is counted.
  it('unmount at 500 ms cancels the timer', () => {
    const { rerender, unmount } = hook();
    rerender({ busy: true });
    at(500);
    expect(vi.getTimerCount()).toBe(1);
    unmount();
    expect(vi.getTimerCount()).toBe(0);
  });

  // StrictMode re-runs the mount effect: the first timer is cancelled, so one timer runs and the text is set once.
  it('under StrictMode: one timer while busy, the text at 1500 ms', () => {
    const { result } = renderHook(({ busy }: { busy: boolean }) => useWaitStatus(busy, T), {
      initialProps: { busy: true },
      wrapper: StrictMode,
    });
    expect(vi.getTimerCount()).toBe(1);
    at(1499);
    expect(result.current).toBe('');
    at(1);
    expect(result.current).toBe(T);
  });
});

// fe-r6-commit-b-red.md F-1 (the source-pin option, chosen over a wired boundary): every surface uses WAIT_STATUS_DELAY_MS, so every
// `useWaitStatus(` call under src/ passes exactly two arguments (busy, text). Read from disk with the TypeScript parser
// (a comma inside a string cannot fool it); the same check runs over planted sources, and the textual count of calls must
// equal the parsed count, so an aliased or unparsed call cannot slip past.
describe('useWaitStatus call sites (FE-R6, commit RED F-1)', () => {
  const ROOT = path.resolve(__dirname, '..');
  const sources = (dir: string): { file: string; source: string }[] =>
    readdirSync(path.join(ROOT, dir))
      .sort()
      .flatMap((name) => {
        const rel = `${dir}/${name}`;
        if (statSync(path.join(ROOT, rel)).isDirectory()) return sources(rel);
        return /\.(ts|tsx)$/.test(name) && !name.endsWith('.d.ts') ? [{ file: rel, source: readFileSync(path.join(ROOT, rel), 'utf8') }] : [];
      });
  /** Each `useWaitStatus(…)` call with its argument count. */
  const calls = (file: string, source: string) => {
    const tree = ts.createSourceFile(file, source, ts.ScriptTarget.Latest, true, file.endsWith('.tsx') ? ts.ScriptKind.TSX : ts.ScriptKind.TS);
    const out: { file: string; args: number }[] = [];
    const visit = (n: ts.Node) => {
      if (ts.isCallExpression(n) && ts.isIdentifier(n.expression) && n.expression.text === 'useWaitStatus') out.push({ file, args: n.arguments.length });
      ts.forEachChild(n, visit);
    };
    visit(tree);
    return out;
  };
  const textual = (source: string) => (source.match(/\buseWaitStatus\s*\(/g) ?? []).length - (source.match(/\bfunction\s+useWaitStatus\s*\(/g) ?? []).length;

  it('every call in src/ takes exactly two arguments, so the delay is always WAIT_STATUS_DELAY_MS', () => {
    const found = sources('src').flatMap(({ file, source }) => {
      const c = calls(file, source);
      expect(c.length, `${file}: every useWaitStatus( is a parsed call`).toBe(textual(source));
      return c;
    });
    // Anti-vacuity: the item page's call is found.
    expect(found.map((c) => c.file)).toContain('src/app/(app)/items/[id]/ItemPage.tsx');
    expect(found.filter((c) => c.args !== 2)).toEqual([]);
  });

  it('the check goes red on a planted three-argument call and sees a call inside a string-bearing argument list', () => {
    const planted = "const w = useWaitStatus(saving, 'a, b', 1100);";
    expect(calls('planted.tsx', planted)).toEqual([{ file: 'planted.tsx', args: 3 }]);
    expect(calls('ok.tsx', "const w = useWaitStatus(saving, 'a, b');")).toEqual([{ file: 'ok.tsx', args: 2 }]);
    expect(textual('export function useWaitStatus(busy) {}\nuseWaitStatus(a, b);')).toBe(1);
  });
});
