import { StrictMode, useRef, useState, type ReactElement } from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { PersonLifecycleActions } from '@/components/people/PersonLifecycleActions';
import { TooltipProvider } from '@/components/ui/Tooltip';
import type { User } from '@/lib/api/users';
import type { BulkResult } from '@/lib/bulk/results';
import type { DeletePersonResult, RestorePersonResult } from '@/lib/people/results';
import { PERSON_ACTIVE, PERSON_DELETED_ACTIVE, VIEWER_ID } from '@/components/preview/fixtures/people';
import { BulkHarness } from './bulk/harness';

// FE-D1: after a successful delete or restore that closes a dialog (or removes the bulk bar), focus moves to the page's
// <h1> FIRST and the result is written SECOND, so a screen reader hears it after focus has landed.
//
// What this can and cannot show. A MutationObserver callback is a microtask: it sees the order of work across TASKS
// (timers, React's scheduler), which is how FE-D1 itself happened (text in one commit, focus on Radix's later timer). It
// cannot see an order inside one task: "write the text, then focus" in the same handler would read as focus-first. So the
// required assertion is the SYNCHRONOUS one: a `focusin` listener runs inside `h1.focus()` and records the DOM exactly as
// it is at the focus move — the result text not yet present, no dialog in the document, no aria-hidden ancestor (the
// last two are what React's sync-lane passive flush and Radix's effect cleanups provide; this test is their guard against
// an upgrade that moves them). Exactly one entry is required, so a second focus move also fails. How Chromium's
// accessibility layer orders the events, and what a screen reader speaks, are measured in the browser run, not here.

/** Resolves after a 20 ms timer: always outside the click's act() scope, as a real network answer is. */
const later = <T,>(value: T) => new Promise<T>((resolve) => setTimeout(() => resolve(value), 20));

interface AtFocus {
  textAlready: boolean;
  dialog: boolean;
  hidden: boolean;
}

function probe(text: string) {
  const atFocus: AtFocus[] = [];
  const onFocusIn = (e: FocusEvent) => {
    const t = e.target as HTMLElement;
    if (t.tagName !== 'H1') return;
    atFocus.push({
      textAlready: (document.body.textContent ?? '').includes(text),
      dialog: document.querySelector('[role="dialog"]') !== null,
      hidden: t.closest('[aria-hidden="true"]') !== null,
    });
  };
  const seen: Array<Element | null> = [];
  const observer = new MutationObserver(() => {
    if (seen.length === 0 && (document.body.textContent ?? '').includes(text)) seen.push(document.activeElement);
  });
  document.addEventListener('focusin', onFocusIn);
  observer.observe(document.body, { childList: true, subtree: true, characterData: true });
  return {
    atFocus: () => atFocus,
    firstInsert: () => seen[0],
    stop: () => {
      observer.disconnect();
      document.removeEventListener('focusin', onFocusIn);
    },
  };
}

const CLEAN: AtFocus[] = [{ textAlready: false, dialog: false, hidden: false }];

function PersonHarness({ initial, onDeleted, onRestored }: { initial: User; onDeleted?: () => void; onRestored?: () => void }) {
  const h1 = useRef<HTMLHeadingElement>(null);
  const [person, setPerson] = useState(initial);
  return (
    <TooltipProvider delayDuration={0}>
      <main>
        <h1 ref={h1} tabIndex={-1}>
          {person.display_name}
        </h1>
        <PersonLifecycleActions
          person={person}
          viewerId={VIEWER_ID}
          canDelete
          canAssign
          onGiveRole={() => undefined}
          onPageTookOver={() => undefined}
          fallbackFocusRef={h1}
          timeZone="UTC"
          onDeleted={onDeleted}
          onRestored={onRestored}
          deletePerson={async () => {
            const r = await later<DeletePersonResult>({ kind: 'deleted' });
            setPerson((p) => ({ ...p, archived_at: '2026-10-27T03:00:00Z', roles: [] }));
            return r;
          }}
          restorePerson={async () => {
            const r = await later<RestorePersonResult>({ kind: 'restored', person: { ...person, archived_at: null } });
            if (r.kind === 'restored') setPerson(r.person);
            return r;
          }}
        />
      </main>
    </TooltipProvider>
  );
}

const settle = () => new Promise((r) => setTimeout(r, 50));

for (const strict of [false, true]) {
  const wrap = (ui: ReactElement) => (strict ? <StrictMode>{ui}</StrictMode> : ui);
  const suffix = strict ? ' (StrictMode)' : '';

  describe(`FE-D1 focus first, then the result${suffix}`, () => {
    const personCases = [
      { name: 'delete', initial: PERSON_ACTIVE, press: 'Delete person', text: "Sample Person was deleted. They're now in the archived people.", prop: 'onDeleted' },
      { name: 'restore', initial: PERSON_DELETED_ACTIVE, press: 'Restore person', text: 'Sample Deleted was restored. Give them a role so they can work.', prop: 'onRestored' },
    ] as const;
    for (const c of personCases) {
      it(`person ${c.name}: the <h1> takes focus before the after-message exists, with the dialog gone; refresh once, after`, async () => {
        const seenText: string[] = [];
        const refresh = vi.fn(() => seenText.push(screen.getByRole('status').textContent ?? ''));
        render(wrap(<PersonHarness initial={c.initial} {...{ [c.prop]: refresh }} />));
        const user = userEvent.setup();
        await user.click(screen.getByRole('button', { name: c.press }));
        const dialog = await screen.findByRole('dialog');
        const p = probe(c.text);
        await user.click(within(dialog).getByRole('button', { name: c.press }));
        await waitFor(() => expect(p.firstInsert()).toBeDefined());
        await settle();
        p.stop();
        const h1 = screen.getByRole('heading', { level: 1 });
        expect(p.atFocus()).toEqual(CLEAN);
        expect(p.firstInsert()).toBe(h1);
        expect(screen.getByRole('status')).toHaveTextContent(c.text);
        await waitFor(() => expect(refresh).toHaveBeenCalledOnce());
        expect(seenText[0]).toBe(c.text);
        await settle();
        expect(refresh).toHaveBeenCalledOnce();
        expect(h1).toHaveFocus();
      });
    }

    it('bulk delete, done: the list <h1> takes focus before "1 of 1 deleted." exists, with the dialog gone; one re-read, after', async () => {
      const text = '1 of 1 deleted.';
      const onReread = vi.fn();
      render(wrap(<BulkHarness run={() => later<BulkResult>({ kind: 'done', applied: 1, requested: 1 })} maxMembers={5} onReread={onReread} />));
      const user = userEvent.setup();
      await user.click(screen.getByRole('checkbox', { name: 'Select Sample Supplier A' }));
      await user.click(screen.getByRole('button', { name: 'Delete' }));
      const dialog = await screen.findByRole('dialog');
      const p = probe(text);
      await user.click(within(dialog).getByRole('button', { name: 'Delete' }));
      await waitFor(() => expect(p.firstInsert()).toBeDefined());
      await settle();
      p.stop();
      const h1 = screen.getByRole('heading', { level: 1, name: 'Suppliers' });
      expect(p.atFocus()).toEqual(CLEAN);
      expect(p.firstInsert()).toBe(h1);
      expect(onReread).toHaveBeenCalledOnce();
      expect(h1).toHaveFocus();
    });

    it('bulk delete, key-expired: the list <h1> takes focus before the "Already sent" notice exists, with the dialog gone', async () => {
      const text = "These were sent more than a day ago, so we can't show that result again. The list shows each record as it is now.";
      const onReread = vi.fn();
      render(wrap(<BulkHarness run={() => later<BulkResult>({ kind: 'key-expired' })} maxMembers={5} onReread={onReread} />));
      const user = userEvent.setup();
      await user.click(screen.getByRole('checkbox', { name: 'Select Sample Supplier A' }));
      await user.click(screen.getByRole('button', { name: 'Delete' }));
      const dialog = await screen.findByRole('dialog');
      const p = probe(text);
      await user.click(within(dialog).getByRole('button', { name: 'Delete' }));
      await waitFor(() => expect(p.firstInsert()).toBeDefined());
      await settle();
      p.stop();
      const h1 = screen.getByRole('heading', { level: 1, name: 'Suppliers' });
      expect(p.atFocus()).toEqual(CLEAN);
      expect(p.firstInsert()).toBe(h1);
      expect(onReread).toHaveBeenCalledOnce();
      expect(h1).toHaveFocus();
    });

    it('bulk restore, done: the list <h1> takes focus before "1 of 1 restored." exists; one re-read', async () => {
      const text = '1 of 1 restored.';
      const onReread = vi.fn();
      render(wrap(<BulkHarness run={() => later<BulkResult>({ kind: 'done', applied: 1, requested: 1 })} maxMembers={5} onReread={onReread} />));
      const user = userEvent.setup();
      await user.click(screen.getByRole('button', { name: 'Scope: archived' }));
      await user.click(screen.getByRole('checkbox', { name: 'Select Sample Supplier G' }));
      const p = probe(text);
      await user.click(screen.getByRole('button', { name: 'Restore' }));
      await waitFor(() => expect(p.firstInsert()).toBeDefined());
      await settle();
      p.stop();
      const h1 = screen.getByRole('heading', { level: 1, name: 'Suppliers' });
      expect(p.atFocus()).toEqual(CLEAN);
      expect(p.firstInsert()).toBe(h1);
      expect(onReread).toHaveBeenCalledOnce();
      expect(h1).toHaveFocus();
    });
  });
}
