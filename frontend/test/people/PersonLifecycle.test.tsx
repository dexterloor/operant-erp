import { readFileSync, readdirSync } from 'node:fs';
import path from 'node:path';
import { StrictMode, useRef, useState } from 'react';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { PersonLifecycleActions } from '@/components/people/PersonLifecycleActions';
import { TooltipProvider } from '@/components/ui/Tooltip';
import type { User } from '@/lib/api/users';
import type { DeletePersonResult, RestorePersonResult } from '@/lib/people/results';
import {
  DELETE_REFUSALS,
  PERSON_ACTIVE,
  PERSON_DELETED_ACTIVE,
  PERSON_DELETED_SWITCHED_OFF,
  PERSON_SELF,
  RESTORE_REFUSALS,
  VIEWER_ID,
} from '@/components/preview/fixtures/people';

// Behaviour of ux-copy-frontend.md §2.2–§2.3 (+ round-6 §2d, R4-1, R4-4 L-2/L-6, 4b-4, RED M-6/M-7) and the converged
// b4-owed-copy.md Revision 2 > 1 (R1-1, R1-3, R1-4, R1-5, R1-7). Copy is asserted as literal text, word for word.

type Answer<R> = R | (() => R | Promise<R>) | 'throw';

interface HarnessProps {
  initial: User;
  canDelete?: boolean;
  canAssign?: boolean;
  onGiveRole?: () => void;
  deleteAnswer?: Answer<DeletePersonResult>;
  restoreAnswer?: Answer<RestorePersonResult>;
  onPageTookOver?: (failure: string) => void;
}

/** A route-shaped wrapper: owns the <h1> and the person prop, and re-reads (replaces the prop) on success. */
function Harness({ initial, canDelete = true, canAssign = true, onGiveRole = () => undefined, deleteAnswer = { kind: 'deleted' }, restoreAnswer, onPageTookOver = () => undefined }: HarnessProps) {
  const h1 = useRef<HTMLHeadingElement>(null);
  const [person, setPerson] = useState(initial);
  const resolve = async <R,>(a: Answer<R>): Promise<R> => {
    if (a === 'throw') throw new Error('network');
    return typeof a === 'function' ? await (a as () => R | Promise<R>)() : a;
  };
  return (
    <TooltipProvider delayDuration={0}>
      <main>
        <h1 ref={h1} tabIndex={-1}>
          {person.id === VIEWER_ID ? `${person.display_name} (you)` : person.display_name}
        </h1>
        <PersonLifecycleActions
          person={person}
          viewerId={VIEWER_ID}
          canDelete={canDelete}
          canAssign={canAssign}
          onGiveRole={onGiveRole}
          onPageTookOver={onPageTookOver}
          fallbackFocusRef={h1}
          timeZone="UTC"
          deletePerson={async () => {
            const r = await resolve(deleteAnswer);
            if (r.kind === 'deleted') setPerson((p) => ({ ...p, archived_at: '2026-10-27T03:00:00Z', roles: [] }));
            return r;
          }}
          restorePerson={async () => {
            const fallback: RestorePersonResult = { kind: 'restored', person: { ...person, archived_at: null } };
            const r = await resolve(restoreAnswer ?? fallback);
            if (r.kind === 'restored') setPerson(r.person);
            return r;
          }}
        />
      </main>
    </TooltipProvider>
  );
}

const DELETE_BODY_1 =
  'Sample Person will be signed out everywhere and won\'t be able to sign in. Every role they have will be taken away, and any role proposals for them or made by them will end.';
const DELETE_BODY_2 =
  "They'll move to the archived people. If you restore them later, they'll sign in with their old password but get no roles back: someone will have to give them their roles again.";
const RESTORE_ACTIVE = (n: string) =>
  `${n} will be able to sign in again, with their old password. No roles come back, so they won't be able to do anything until someone gives them a role.`;
const RESTORE_OFF = (n: string) => `${n}'s account will come back switched off, so they still won't be able to sign in. No roles come back.`;

const status = () => screen.getByRole('status');

/**
 * The injected action has answered and its result is rendered: the sending button's `aria-busy` (set synchronously by
 * the click) is gone. Await this after every click that sends, before asserting on the result — the answer may land
 * in a later task than the click (b4-step3-progress.md "Flake").
 */
const settled = () => waitFor(() => expect(document.querySelector('[aria-busy="true"]')).toBeNull());


async function openDelete() {
  const user = userEvent.setup();
  await user.click(screen.getByRole('button', { name: 'Delete person' }));
  return { user, dialog: await screen.findByRole('dialog', { name: 'Delete Sample Person?' }) };
}
async function openRestore(name: string) {
  const user = userEvent.setup();
  await user.click(screen.getByRole('button', { name: 'Restore person' }));
  return { user, dialog: await screen.findByRole('dialog', { name: `Restore ${name}?` }) };
}

describe('§2.2 Delete — rendering', () => {
  it('is not rendered without app_user.delete (ST-4c); the live region still exists, empty', () => {
    render(<Harness initial={PERSON_ACTIVE} canDelete={false} />);
    expect(screen.queryByRole('button', { name: 'Delete person' })).toBeNull();
    expect(status()).toHaveTextContent('');
  });

  it('on your own page: aria-disabled with the visible reason, tied by aria-describedby, and no dialog opens', async () => {
    render(<Harness initial={PERSON_SELF} />);
    const button = screen.getByRole('button', { name: 'Delete person' });
    expect(button).toHaveAttribute('aria-disabled', 'true');
    expect(button).toHaveAccessibleDescription("You can't delete your own account. Ask someone else who manages people.");
    expect(screen.getByText("You can't delete your own account. Ask someone else who manages people.")).toBeVisible();
    await userEvent.setup().click(button);
    expect(screen.queryByRole('dialog')).toBeNull();
  });

  it('the dialog: title, both body paragraphs as its description, Delete person (danger) · Cancel; initial focus Cancel', async () => {
    render(<Harness initial={PERSON_ACTIVE} />);
    const { dialog } = await openDelete();
    expect(dialog).toHaveAccessibleDescription(`${DELETE_BODY_1} ${DELETE_BODY_2}`);
    const buttons = within(dialog).getAllByRole('button').map((b) => b.textContent);
    expect(buttons).toEqual(['Cancel', 'Delete person']);
    await waitFor(() => expect(within(dialog).getByRole('button', { name: 'Cancel' })).toHaveFocus());
  });
});

describe('§2.2 Delete — outcomes', () => {
  it('success: the after-message speaks, the page swaps to the archived banner, focus goes to the <h1> (opener gone)', async () => {
    render(<Harness initial={PERSON_ACTIVE} />);
    const regionBefore = status();
    const { user, dialog } = await openDelete();
    await user.click(within(dialog).getByRole('button', { name: 'Delete person' }));
    await settled();
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(status()).toBe(regionBefore);
    expect(status()).toHaveTextContent("Sample Person was deleted. They're now in the archived people.");
    expect(screen.getByText(/This person was deleted on/)).toHaveTextContent('This person was deleted on 27 Oct 2026.');
    expect(screen.queryByRole('button', { name: 'Delete person' })).toBeNull();
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveFocus());
  });

  for (const refusal of DELETE_REFUSALS) {
    it(`refusal ${refusal.rule}: "Not deleted" inside the open dialog, body = the server's sentence, focus on its heading`, async () => {
      render(<Harness initial={PERSON_ACTIVE} deleteAnswer={refusal} />);
      const { user, dialog } = await openDelete();
      await user.click(within(dialog).getByRole('button', { name: 'Delete person' }));
      await settled();
      expect(screen.getByRole('dialog')).toBe(dialog);
      await waitFor(() => expect(within(dialog).getByText('Not deleted')).toHaveFocus());
      expect(within(dialog).getByText(refusal.message)).toBeInTheDocument();
      expect(status()).toHaveTextContent('');
      // Focus stays trapped in the dialog.
      await user.tab();
      expect(dialog.contains(document.activeElement)).toBe(true);
    });
  }

  it('renders whatever sentence the server sends (never a stored copy)', async () => {
    const message = 'A sentence the client has never seen, 7f3a.';
    render(<Harness initial={PERSON_ACTIVE} deleteAnswer={{ kind: 'refused', rule: 'app_user.new_rule', message }} />);
    const { user, dialog } = await openDelete();
    await user.click(within(dialog).getByRole('button', { name: 'Delete person' }));
    await settled();
    expect(within(dialog).getByText(message)).toBeInTheDocument();
  });

  it('404: ST-5 RULED sentences under "Not deleted"', async () => {
    render(<Harness initial={PERSON_ACTIVE} deleteAnswer={{ kind: 'not-found' }} />);
    const { user, dialog } = await openDelete();
    await user.click(within(dialog).getByRole('button', { name: 'Delete person' }));
    await settled();
    await waitFor(() => expect(within(dialog).getByText('Not deleted')).toHaveFocus());
    expect(within(dialog).getByText("We couldn't find that. It may have been deleted, or you may not have access.")).toBeInTheDocument();
  });

  it('a repeated refusal moves focus to its heading again', async () => {
    const refusal = DELETE_REFUSALS[0]!;
    render(<Harness initial={PERSON_ACTIVE} deleteAnswer={refusal} />);
    const { user, dialog } = await openDelete();
    const confirm = within(dialog).getByRole('button', { name: 'Delete person' });
    await user.click(confirm);
    await settled();
    await user.click(confirm);
    await settled();
    await waitFor(() => expect(within(dialog).getByText('Not deleted')).toHaveFocus());
  });
});

describe('§2.3 Restore — rendering', () => {
  it('archived page: the banner with the DT-1 date, Restore person; no Delete person', () => {
    render(<Harness initial={PERSON_DELETED_ACTIVE} />);
    expect(screen.getByText(/This person was deleted on/)).toHaveTextContent('This person was deleted on 22 Oct 2026.');
    expect(screen.getByRole('button', { name: 'Restore person' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Delete person' })).toBeNull();
  });

  it('without app_user.delete: the banner stays (the page\'s explanation), Restore person is not rendered', () => {
    render(<Harness initial={PERSON_DELETED_ACTIVE} canDelete={false} />);
    expect(screen.getByText(/This person was deleted on/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Restore person' })).toBeNull();
  });

  it('served is_active true: the "sign in again" body only', async () => {
    render(<Harness initial={PERSON_DELETED_ACTIVE} />);
    const { dialog } = await openRestore('Sample Deleted');
    expect(dialog).toHaveAccessibleDescription(RESTORE_ACTIVE('Sample Deleted'));
    expect(within(dialog).queryByText(RESTORE_OFF('Sample Deleted'))).toBeNull();
    expect(within(dialog).getAllByRole('button').map((b) => b.textContent)).toEqual(['Cancel', 'Restore person']);
    await waitFor(() => expect(within(dialog).getByRole('button', { name: 'Cancel' })).toHaveFocus());
  });

  it('served is_active false: the "switched off" body only', async () => {
    render(<Harness initial={PERSON_DELETED_SWITCHED_OFF} />);
    const { dialog } = await openRestore('Sample Switched Off');
    expect(dialog).toHaveAccessibleDescription(RESTORE_OFF('Sample Switched Off'));
    expect(within(dialog).queryByText(RESTORE_ACTIVE('Sample Switched Off'))).toBeNull();
  });
});

describe('§2.3 Restore — outcomes', () => {
  it('active: "{name} was restored. Give them a role so they can work." + Give a role (with role.assign); focus to <h1>', async () => {
    const onGiveRole = vi.fn();
    render(<Harness initial={PERSON_DELETED_ACTIVE} onGiveRole={onGiveRole} />);
    const regionBefore = status();
    const { user, dialog } = await openRestore('Sample Deleted');
    await user.click(within(dialog).getByRole('button', { name: 'Restore person' }));
    await settled();
    expect(status()).toBe(regionBefore);
    expect(status()).toHaveTextContent('Sample Deleted was restored. Give them a role so they can work.');
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveFocus());
    await user.click(screen.getByRole('button', { name: 'Give a role' }));
    expect(onGiveRole).toHaveBeenCalledOnce();
    expect(screen.getByRole('button', { name: 'Delete person' })).toBeInTheDocument();
  });

  it('active without role.assign: the sentence, but no Give a role', async () => {
    render(<Harness initial={PERSON_DELETED_ACTIVE} canAssign={false} />);
    const { user, dialog } = await openRestore('Sample Deleted');
    await user.click(within(dialog).getByRole('button', { name: 'Restore person' }));
    await settled();
    expect(status()).toHaveTextContent('Sample Deleted was restored. Give them a role so they can work.');
    expect(screen.queryByRole('button', { name: 'Give a role' })).toBeNull();
  });

  it('switched off: only "{name} was restored." — no second sentence, no Give a role even with role.assign', async () => {
    render(<Harness initial={PERSON_DELETED_SWITCHED_OFF} />);
    const { user, dialog } = await openRestore('Sample Switched Off');
    await user.click(within(dialog).getByRole('button', { name: 'Restore person' }));
    await settled();
    expect(status().textContent).toBe('Sample Switched Off was restored.');
    expect(screen.queryByRole('button', { name: 'Give a role' })).toBeNull();
  });

  it('Dismiss message empties the region (same node) and sends focus to the <h1>', async () => {
    render(<Harness initial={PERSON_DELETED_SWITCHED_OFF} />);
    const regionBefore = status();
    const { user, dialog } = await openRestore('Sample Switched Off');
    await user.click(within(dialog).getByRole('button', { name: 'Restore person' }));
    await settled();
    await user.click(screen.getByRole('button', { name: 'Dismiss message' }));
    expect(status()).toBe(regionBefore);
    expect(status()).toHaveTextContent('');
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveFocus());
  });

  for (const refusal of RESTORE_REFUSALS) {
    it(`refusal ${refusal.rule}: "Not restored" inside the dialog, the server's sentence, focus on its heading`, async () => {
      render(<Harness initial={PERSON_DELETED_ACTIVE} restoreAnswer={refusal} />);
      const { user, dialog } = await openRestore('Sample Deleted');
      await user.click(within(dialog).getByRole('button', { name: 'Restore person' }));
      await settled();
      await waitFor(() => expect(within(dialog).getByText('Not restored')).toHaveFocus());
      expect(within(dialog).getByText(refusal.message)).toBeInTheDocument();
    });
  }
});

describe('interrupted and fallback answers (b4-owed-copy.md Revision 2 > 1)', () => {
  const cases: { label: string; answer: Answer<DeletePersonResult>; heading: string; body: string }[] = [
    { label: 'unreachable', answer: { kind: 'unreachable' }, heading: "We couldn't reach the server.", body: "We can't tell whether Sample Person was deleted. Press Delete person to try again." },
    { label: 'a thrown action (= unreachable)', answer: 'throw', heading: "We couldn't reach the server.", body: "We can't tell whether Sample Person was deleted. Press Delete person to try again." },
    { label: '5xx', answer: { kind: 'server-error' }, heading: 'Something went wrong on our side.', body: "We can't tell whether Sample Person was deleted. Press Delete person to try again." },
    { label: '403', answer: { kind: 'forbidden' }, heading: 'Not deleted', body: 'This needs the Delete and restore people permission, which you no longer have. Your workspace administrator can grant it.' },
    { label: 'a refusal without a message', answer: { kind: 'refused', rule: 'x', message: '' }, heading: 'Not deleted', body: "This change wasn't made. Reload the page to see where things stand, then try again." },
  ];
  for (const c of cases) {
    it(`delete, ${c.label}: R1 heading + body inside the dialog, focused, no live role`, async () => {
      render(<Harness initial={PERSON_ACTIVE} deleteAnswer={c.answer} />);
      const { user, dialog } = await openDelete();
      await user.click(within(dialog).getByRole('button', { name: 'Delete person' }));
      await settled();
      const heading = within(dialog).getByText(c.heading);
      await waitFor(() => expect(heading).toHaveFocus());
      expect(heading.parentElement).toHaveTextContent(c.body);
      expect(within(dialog).queryByRole('alert')).toBeNull();
      expect(within(dialog).queryByRole('status')).toBeNull();
    });
  }

  it('403 names the permission in italics (R1-5)', async () => {
    render(<Harness initial={PERSON_ACTIVE} deleteAnswer={{ kind: 'forbidden' }} />);
    const { user, dialog } = await openDelete();
    await user.click(within(dialog).getByRole('button', { name: 'Delete person' }));
    await settled();
    expect(within(dialog).getByText('Delete and restore people').tagName).toBe('EM');
  });

  it('restore, unreachable and 5xx: the restore body', async () => {
    render(<Harness initial={PERSON_DELETED_ACTIVE} restoreAnswer={{ kind: 'server-error' }} />);
    const { user, dialog } = await openRestore('Sample Deleted');
    await user.click(within(dialog).getByRole('button', { name: 'Restore person' }));
    await settled();
    await waitFor(() => expect(within(dialog).getByText('Something went wrong on our side.')).toHaveFocus());
    expect(within(dialog).getByText("We can't tell whether Sample Deleted was restored. Press Restore person to try again.")).toBeInTheDocument();
  });

  it('401: no in-page copy (R1-4) — the dialog is left as it is for the caller to navigate', async () => {
    render(<Harness initial={PERSON_ACTIVE} deleteAnswer={{ kind: 'session-ended' }} />);
    const { user, dialog } = await openDelete();
    await user.click(within(dialog).getByRole('button', { name: 'Delete person' }));
    await settled();
    expect(screen.getByRole('dialog')).toBe(dialog);
    expect(dialog.querySelector('[tabindex="-1"]')).toBeNull();
    expect(status()).toHaveTextContent('');
  });
});

/** A pending answer the test resolves by hand (M-1: a request still in flight). */
function deferred<R>() {
  let resolve!: (r: R) => void;
  const promise = new Promise<R>((r) => (resolve = r));
  return { promise, resolve };
}
/** A pointer-down outside the dialog's content (Radix listens on the document; userEvent refuses under pointer-events: none). */
const pointerDownOutside = () => fireEvent.pointerDown(document.body);

describe('a request in flight keeps the dialog open (b4-final-red M-1, spar R-2)', () => {
  it('control: with nothing in flight, a pointer-down outside closes the dialog (so the busy case below can fail)', async () => {
    render(<Harness initial={PERSON_ACTIVE} />);
    await openDelete();
    pointerDownOutside();
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
  });

  it('delete: no Cancel while pending; Escape and a pointer-down outside are ignored; unreachable → R1-1 banner, focused, Cancel back', async () => {
    const d = deferred<DeletePersonResult>();
    render(<Harness initial={PERSON_ACTIVE} deleteAnswer={() => d.promise} />);
    const { user, dialog } = await openDelete();
    const send = within(dialog).getByRole('button', { name: 'Delete person' });
    await user.click(send);
    expect(send).toHaveAttribute('aria-busy', 'true');
    expect(within(dialog).queryByRole('button', { name: 'Cancel' })).toBeNull();
    await user.keyboard('{Escape}');
    pointerDownOutside();
    expect(screen.getByRole('dialog')).toBe(dialog);
    expect(send).toHaveFocus();
    d.resolve({ kind: 'unreachable' });
    await settled();
    expect(screen.getByRole('dialog')).toBe(dialog);
    await waitFor(() => expect(within(dialog).getByText("We couldn't reach the server.")).toHaveFocus());
    expect(within(dialog).getByRole('button', { name: 'Cancel' })).toBeInTheDocument();
    expect(dialog.querySelector('[disabled]')).toBeNull();
  });

  it('a click that does not focus the button (macOS Safari/Firefox): Cancel goes from under focus, which stays inside the dialog (L-D1)', async () => {
    const d = deferred<DeletePersonResult>();
    render(<Harness initial={PERSON_ACTIVE} deleteAnswer={() => d.promise} />);
    const { dialog } = await openDelete();
    const cancel = within(dialog).getByRole('button', { name: 'Cancel' });
    await waitFor(() => expect(cancel).toHaveFocus());
    fireEvent.click(within(dialog).getByRole('button', { name: 'Delete person' }));
    await waitFor(() => expect(within(dialog).queryByRole('button', { name: 'Cancel' })).toBeNull());
    expect(cancel.isConnected).toBe(false);
    await waitFor(() => expect(dialog.contains(document.activeElement)).toBe(true));
    expect(document.activeElement).not.toBe(document.body);
    d.resolve({ kind: 'unreachable' });
    await settled();
    await waitFor(() => expect(within(dialog).getByText("We couldn't reach the server.")).toHaveFocus());
  });

  it('restore: the same while pending; the answer lands inside the open dialog', async () => {
    const d = deferred<RestorePersonResult>();
    render(<Harness initial={PERSON_DELETED_ACTIVE} restoreAnswer={() => d.promise} />);
    const { user, dialog } = await openRestore('Sample Deleted');
    await user.click(within(dialog).getByRole('button', { name: 'Restore person' }));
    expect(within(dialog).queryByRole('button', { name: 'Cancel' })).toBeNull();
    await user.keyboard('{Escape}');
    pointerDownOutside();
    expect(screen.getByRole('dialog')).toBe(dialog);
    d.resolve({ kind: 'unreachable' });
    await settled();
    await waitFor(() => expect(within(dialog).getByText("We couldn't reach the server.")).toHaveFocus());
    expect(within(dialog).getByRole('button', { name: 'Cancel' })).toBeInTheDocument();
  });
});

describe('focus rules (R4-4 L-6, 4b-4, RED M-7)', () => {
  it('Esc closes and returns focus to the opener', async () => {
    render(<Harness initial={PERSON_ACTIVE} />);
    const opener = screen.getByRole('button', { name: 'Delete person' });
    const { user } = await openDelete();
    await user.keyboard('{Escape}');
    expect(screen.queryByRole('dialog')).toBeNull();
    await waitFor(() => expect(opener).toHaveFocus());
  });

  it('Cancel after a refusal closes, clears the banner, and the next open starts clean on Cancel', async () => {
    render(<Harness initial={PERSON_ACTIVE} deleteAnswer={DELETE_REFUSALS[0]!} />);
    const { user, dialog } = await openDelete();
    await user.click(within(dialog).getByRole('button', { name: 'Delete person' }));
    await settled();
    await user.click(within(dialog).getByRole('button', { name: 'Cancel' }));
    const again = await openDelete();
    expect(within(again.dialog).queryByText('Not deleted')).toBeNull();
    await waitFor(() => expect(within(again.dialog).getByRole('button', { name: 'Cancel' })).toHaveFocus());
  });

  it('StrictMode: one announcement, focus rules unchanged', async () => {
    render(
      <StrictMode>
        <Harness initial={PERSON_ACTIVE} />
      </StrictMode>,
    );
    const { user, dialog } = await openDelete();
    await waitFor(() => expect(within(dialog).getByRole('button', { name: 'Cancel' })).toHaveFocus());
    await user.click(within(dialog).getByRole('button', { name: 'Delete person' }));
    await settled();
    expect(screen.getAllByRole('status')).toHaveLength(1);
    expect(status()).toHaveTextContent("Sample Person was deleted. They're now in the archived people.");
    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveFocus());
  });
});

describe('no server sentence is stored in client code (ST-3, review L-6)', () => {
  it('none of the shipped refusal sentences appears in src/copy or src/components/people', () => {
    const root = path.resolve(__dirname, '../..');
    const sources = ['src/copy', 'src/components/people'].flatMap((d) =>
      readdirSync(path.join(root, d))
        .filter((f) => /\.tsx?$/.test(f))
        .map((f) => readFileSync(path.join(root, d, f), 'utf8')),
    );
    expect(sources.length).toBeGreaterThan(4);
    // One sentence is shared by design: the server's own-account delete refusal (`app_user.actor_is_subject` on delete)
    // adopted UX's own-page reason word for word (ux-copy-frontend.md §2.2, backend 01cbb2e). The client renders its own
    // copy for the disabled button and the server's message for a stale page, so neither is a stored server sentence.
    // Typed here so the exemption cannot widen: exactly this rule with exactly this sentence, and it must still be served.
    const SHARED_BY_DESIGN = { rule: 'app_user.actor_is_subject', message: "You can't delete your own account. Ask someone else who manages people." };
    const all = [...DELETE_REFUSALS, ...RESTORE_REFUSALS];
    expect(all.filter((r) => r.rule === SHARED_BY_DESIGN.rule && r.message === SHARED_BY_DESIGN.message)).toHaveLength(1);
    const messages = all.filter((r) => !(r.rule === SHARED_BY_DESIGN.rule && r.message === SHARED_BY_DESIGN.message)).map((r) => r.message);
    // Compare with typographic quotes and escapes flattened, so a re-quoted copy is still caught.
    const norm = (s: string) => s.replace(/\\'/g, "'").replace(/[‘’]/g, "'");
    const leaked = messages.filter((m) => sources.some((src) => norm(src).includes(norm(m))));
    expect(leaked).toEqual([]);
  });
});

// FE-R1 L-2 (fe-r1-red.md): a delete whose re-read did not answer a person carries the failure in its result, and the
// component hands it to the page — the one required callback, no fallback stop on the <h1> — before it speaks.
describe('§2.2 Delete — the page takes over (FE-R1 FE-D1b / R-14b)', () => {
  it.each(['server-error', 'unreachable', 'forbidden', 'not-found'] as const)('pageTookOver %s: the page is called once with it, no <h1> stop, the message after the call', async (failure) => {
    const MESSAGE = "Sample Person was deleted. They're now in the archived people.";
    const calls: { failure: string; statusAtCall: string }[] = [];
    // Stands in for the page's region error / swap heading: the page moves focus there itself, inside the call.
    const target = document.createElement('h2');
    target.tabIndex = -1;
    target.textContent = 'page target';
    document.body.appendChild(target);
    render(
      <Harness
        initial={PERSON_ACTIVE}
        deleteAnswer={{ kind: 'deleted', pageTookOver: failure }}
        onPageTookOver={(f) => {
          calls.push({ failure: f, statusAtCall: screen.getByRole('status').textContent ?? '' });
          target.focus();
        }}
      />,
    );
    const { user, dialog } = await openDelete();
    const focusSeq: string[] = [];
    const onFocusIn = (e: FocusEvent) => focusSeq.push((e.target as HTMLElement).tagName);
    document.addEventListener('focusin', onFocusIn);
    await user.click(within(dialog).getByRole('button', { name: 'Delete person' }));
    await settled();
    await new Promise((r) => setTimeout(r, 50));
    document.removeEventListener('focusin', onFocusIn);
    expect(calls).toEqual([{ failure, statusAtCall: '' }]);
    expect(focusSeq).toEqual(['BUTTON', 'H2']);
    expect(target).toHaveFocus();
    expect(status()).toHaveTextContent(MESSAGE);
    target.remove();
  });
});
