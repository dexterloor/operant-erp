import { StrictMode, useRef, useState, type ReactNode } from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { Button } from '@/components/ui/Button';
import { Checkbox } from '@/components/ui/Checkbox';
import { Chip } from '@/components/ui/Chip';
import { DateTime } from '@/components/ui/DateTime';
import { Dialog } from '@/components/ui/Dialog';
import { LiveRegion, useLiveRegion } from '@/components/ui/LiveRegion';
import { TooltipProvider } from '@/components/ui/Tooltip';

// DOM fitness (b4-architecture.md Revision 1 "Tests", RED H-5 item 3) — rendered, never grepped:
//  1. no element carries the native [disabled] attribute (ST-4e: aria-disabled only);
//  2. every [role=status] / [aria-live] region exists at first render, EMPTY (R4-4 L-2);
//  3. after each scripted action the same region nodes are present (identity) and no new region has appeared.
// Applied to the step-1 primitives here; each later B-4 step adds its surface to this file. The checker is exercised
// against planted failures below so it can be seen to go red.

const REGION = '[role="status"], [aria-live]';

function disabledViolations(root: ParentNode): string[] {
  return [...root.querySelectorAll('[disabled]')].map((el) => `[disabled] on <${el.tagName.toLowerCase()}>`);
}

/** Snapshot the regions at first render; `check()` after each action. Root defaults to `document.body` (portals). */
function trackRegions(root: ParentNode = document.body) {
  const initial = [...root.querySelectorAll(REGION)];
  const first: string[] = [...disabledViolations(root)];
  initial.forEach((el) => {
    if ((el.textContent ?? '') !== '') first.push(`region filled at first render: "${el.textContent}"`);
  });
  return {
    count: initial.length,
    firstRender: first,
    check(): string[] {
      const out = [...disabledViolations(root)];
      const now = [...root.querySelectorAll(REGION)];
      initial.forEach((el, i) => {
        if (!el.isConnected || !now.includes(el)) out.push(`region #${i} was re-mounted or removed`);
      });
      now.forEach((el) => {
        if (!initial.includes(el)) out.push(`region appeared after first render: "${el.textContent}"`);
      });
      return out;
    },
  };
}

function Primitives() {
  const live = useLiveRegion();
  const [ticked, setTicked] = useState<boolean | 'mixed'>('mixed');
  const [open, setOpen] = useState(false);
  const cancel = useRef<HTMLButtonElement>(null);
  const h1 = useRef<HTMLHeadingElement>(null);
  return (
    <TooltipProvider delayDuration={0}>
      <h1 ref={h1} tabIndex={-1}>
        Preview
      </h1>
      <LiveRegion message={live.message} />
      <Checkbox
        label="All in Sales"
        checked={ticked}
        onCheckedChange={(next) => {
          setTicked(next);
          live.announce(next ? 'All ticked.' : 'None ticked.');
        }}
      />
      <Checkbox label="Unavailable" checked={false} onCheckedChange={() => {}} unavailable describedBy="why" />
      <p id="why">Only an owner can give this role.</p>
      <Chip tone="info">Built-in</Chip>
      <DateTime value="2026-10-22T06:05:00Z" form="date" timeZone="UTC" />
      <Button unavailableReason="Select fewer.">Archive</Button>
      <Button onClick={() => setOpen(true)}>Open dialog</Button>
      <Dialog open={open} onOpenChange={setOpen} title="Archive 3 items?" description={<p>You can restore them.</p>} initialFocusRef={cancel} fallbackFocusRef={h1}>
        <Button ref={cancel} onClick={() => setOpen(false)}>
          Cancel
        </Button>
      </Dialog>
    </TooltipProvider>
  );
}

async function script() {
  const user = userEvent.setup();
  const steps: Array<() => Promise<void>> = [
    () => user.click(screen.getByRole('checkbox', { name: 'All in Sales' })),
    () => user.click(screen.getByRole('checkbox', { name: 'Unavailable' })),
    () => user.click(screen.getByRole('button', { name: 'Open dialog' })),
    () => user.keyboard('{Escape}'),
    () => user.tab(),
    () => user.click(screen.getByRole('checkbox', { name: 'All in Sales' })),
  ];
  return steps;
}

async function runFitness(ui: ReactNode) {
  render(ui);
  const tracker = trackRegions();
  const failures = [...tracker.firstRender];
  for (const step of await script()) {
    await step();
    failures.push(...tracker.check());
  }
  return { failures, count: tracker.count };
}

describe('DOM fitness — step-1 primitives', () => {
  it('no [disabled]; one region, empty at first render, the same node after every action', async () => {
    const { failures, count } = await runFitness(<Primitives />);
    expect(count).toBe(1);
    expect(failures).toEqual([]);
    expect(screen.getByRole('status')).toHaveTextContent('None ticked.');
  });

  it('the same under StrictMode (effects double-run)', async () => {
    const { failures, count } = await runFitness(
      <StrictMode>
        <Primitives />
      </StrictMode>,
    );
    expect(count).toBe(1);
    expect(failures).toEqual([]);
  });
});

describe('DOM fitness — the checker goes red on planted failures', () => {
  it('a native [disabled] attribute', () => {
    render(<input type="checkbox" disabled aria-label="x" />);
    expect(trackRegions().firstRender).toEqual(['[disabled] on <input>']);
  });

  it('a region already filled at first render', () => {
    render(<div role="status">Saved</div>);
    expect(trackRegions().firstRender).toEqual(['region filled at first render: "Saved"']);
  });

  it('a region inside a branch that swaps (re-mounted node)', async () => {
    function Swapping() {
      const [done, setDone] = useState(false);
      // Two different branch wrappers, so React cannot reuse the region node (an unwrapped fragment would let it).
      return done ? (
        <section>
          <div role="status">Deleted.</div>
        </section>
      ) : (
        <article>
          <div role="status" />
          <button type="button" onClick={() => setDone(true)}>
            Go
          </button>
        </article>
      );
    }
    render(<Swapping />);
    const tracker = trackRegions();
    await userEvent.setup().click(screen.getByRole('button', { name: 'Go' }));
    expect(tracker.check()).toEqual(['region #0 was re-mounted or removed', 'region appeared after first render: "Deleted."']);
  });

  it('an aria-live region without a role that appears only after an action', async () => {
    function Late() {
      const [shown, setShown] = useState(false);
      return (
        <>
          {shown ? <p aria-live="polite">Done.</p> : null}
          <button type="button" onClick={() => setShown(true)}>
            Go
          </button>
        </>
      );
    }
    render(<Late />);
    const tracker = trackRegions();
    expect(tracker.count).toBe(0);
    await userEvent.setup().click(screen.getByRole('button', { name: 'Go' }));
    expect(tracker.check()).toEqual(['region appeared after first render: "Done."']);
  });
});
