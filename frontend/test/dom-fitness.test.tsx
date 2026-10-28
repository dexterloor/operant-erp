import { StrictMode, useRef, useState, type ReactNode } from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { Button } from '@/components/ui/Button';
import { Checkbox } from '@/components/ui/Checkbox';
import { Chip } from '@/components/ui/Chip';
import { DateTime } from '@/components/ui/DateTime';
import { Dialog } from '@/components/ui/Dialog';
import { LiveRegion, useLiveRegion } from '@/components/ui/LiveRegion';
import { TooltipProvider } from '@/components/ui/Tooltip';
import { PersonLifecycleActions } from '@/components/people/PersonLifecycleActions';
import type { User } from '@/lib/api/users';
import { BulkHarness } from './bulk/harness';
import { BULK_CONTENTION } from '@/components/preview/fixtures/bulk';
import { DELETE_REFUSALS, PERSON_ACTIVE, PERSON_SELF, VIEWER_ID } from '@/components/preview/fixtures/people';

/** The injected action has answered and its result is rendered (no `aria-busy` left) — see b4-step3-progress.md "Flake". */
const settled = () => waitFor(() => expect(document.querySelector('[aria-busy="true"]')).toBeNull());


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
    await settled();
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

// B-4 step 2: the person surface. The script crosses the delete → archived branch swap and back (restore), with a
// refusal in between, so the one after-message region must be the same node throughout (R4-4 L-2, RED M-6).
function PersonSurface({ initial }: { initial: User }) {
  const h1 = useRef<HTMLHeadingElement>(null);
  const [person, setPerson] = useState(initial);
  const [refuseNext, setRefuseNext] = useState(true);
  return (
    <TooltipProvider delayDuration={0}>
      <h1 ref={h1} tabIndex={-1}>
        {person.display_name}
      </h1>
      <PersonLifecycleActions
        person={person}
        viewerId={VIEWER_ID}
        canDelete
        canAssign
        onGiveRole={() => undefined}
        fallbackFocusRef={h1}
        timeZone="UTC"
        deletePerson={async () => {
          if (refuseNext) {
            setRefuseNext(false);
            return DELETE_REFUSALS[0]!;
          }
          setPerson((p) => ({ ...p, archived_at: '2026-10-27T03:00:00Z' }));
          return { kind: 'deleted' };
        }}
        restorePerson={async () => {
          const restored = { ...person, archived_at: null };
          setPerson(restored);
          return { kind: 'restored', person: restored };
        }}
      />
    </TooltipProvider>
  );
}

async function personScript() {
  const user = userEvent.setup();
  const confirm = (name: string) => () => user.click(screen.getAllByRole('button', { name }).at(-1)!);
  return [
    () => user.click(screen.getByRole('button', { name: 'Delete person' })),
    confirm('Delete person'), // refused inside the dialog
    confirm('Delete person'), // deleted: the branch swaps to the archived banner
    () => user.click(screen.getByRole('button', { name: 'Restore person' })),
    confirm('Restore person'), // restored: swaps back
    () => user.click(screen.getByRole('button', { name: 'Dismiss message' })),
  ];
}

describe('DOM fitness — B-4 step 2 person surface', () => {
  for (const strict of [false, true]) {
    it(`no [disabled]; one region, empty at first render, the same node across delete → archived → restored${strict ? ' (StrictMode)' : ''}`, async () => {
      const ui = <PersonSurface initial={PERSON_ACTIVE} />;
      render(strict ? <StrictMode>{ui}</StrictMode> : ui);
      const tracker = trackRegions();
      const failures = [...tracker.firstRender];
      const seen: string[] = [];
      for (const step of await personScript()) {
        await step();
        await settled();
    await settled();
        failures.push(...tracker.check());
        seen.push(screen.getByRole('status').textContent ?? '');
      }
      expect(tracker.count).toBe(1);
      expect(failures).toEqual([]);
      expect(seen).toEqual([
        '',
        '',
        "Sample Person was deleted. They're now in the archived people.",
        "Sample Person was deleted. They're now in the archived people.",
        'Sample Person was restored. Give them a role so they can work.',
        '',
      ]);
    });
  }

  it('own page: the aria-disabled Delete person carries no [disabled]', () => {
    render(<PersonSurface initial={PERSON_SELF} />);
    expect(trackRegions().firstRender).toEqual([]);
  });
});

// B-4 step 3: the bulk surface. The script selects, is refused inside the Delete dialog, deletes (the selection clears
// and the bar goes), dismisses, switches to the Archived view, restores and is refused in the banner zone, then
// restores — so the one after-message region, rendered by the list outside the bar (RED M-6), must be the same node
// throughout, mounted empty from first render.
describe('DOM fitness — B-4 step 3 bulk surface', () => {
  for (const strict of [false, true]) {
    it(`no [disabled]; one region, empty at first render, the same node while the bar comes and goes${strict ? ' (StrictMode)' : ''}`, async () => {
      const answers = [BULK_CONTENTION, { kind: 'done' as const, requested: 2, applied: 2 }, BULK_CONTENTION, { kind: 'done' as const, requested: 3, applied: 3 }];
      const ui = <BulkHarness run={async () => answers.shift()!} maxMembers={3} />;
      render(strict ? <StrictMode>{ui}</StrictMode> : ui);
      const tracker = trackRegions();
      const failures = [...tracker.firstRender];
      const user = userEvent.setup();
      const status = () => document.querySelector('[role="status"]')?.textContent ?? null;
      const seen: Array<string | null> = [];
      const steps: Array<() => Promise<void>> = [
        () => user.click(screen.getByRole('checkbox', { name: 'Select Sample Supplier A' })),
        () => user.click(screen.getByRole('checkbox', { name: 'Select every row on this page' })), // 4 > cap 3
        () => user.click(screen.getByRole('checkbox', { name: 'Select Sample Supplier D' })),
        () => user.click(screen.getByRole('checkbox', { name: 'Select Sample Supplier C' })),
        () => user.click(screen.getByRole('button', { name: 'Delete' })),
        () => user.click(screen.getAllByRole('button', { name: 'Delete' }).at(-1)!), // refused in the dialog
        () => user.click(screen.getAllByRole('button', { name: 'Delete' }).at(-1)!), // done: the bar goes
        () => user.click(screen.getByRole('button', { name: 'Dismiss message' })),
        () => user.click(screen.getByRole('button', { name: 'Scope: archived' })),
        () => user.click(screen.getByRole('checkbox', { name: 'Select every row on this page' })),
        () => user.click(screen.getByRole('button', { name: 'Restore' })), // refused in the banner zone
        () => user.click(screen.getByRole('button', { name: 'Restore' })), // done
      ];
      for (const step of steps) {
        await step();
        await settled();
    await settled();
        failures.push(...tracker.check());
        seen.push(status());
      }
      expect(tracker.count).toBe(1);
      expect(failures).toEqual([]);
      expect(seen).toEqual(['', '', '', '', '', '', '2 of 2 deleted.', '', '', '', '', '3 of 3 restored.']);
    });
  }
});
