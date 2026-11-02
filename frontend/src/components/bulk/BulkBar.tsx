'use client';

import { useState, type ReactNode, type RefObject } from 'react';
import { flushSync } from 'react-dom';
import { Archive, ArchiveRestore } from 'lucide-react';
import type { BulkResult, BulkRunAction } from '@/lib/bulk/results';
import { isOverCap } from '@/lib/bulk/selection';
import { plural } from '@/lib/format/plural';
import {
  BULK,
  BULK_AFTER,
  BULK_ALREADY_SENT,
  BULK_INTERRUPTED,
  bulkForbidden,
  type BulkNouns,
} from '@/copy/bulk';
import { COULD_NOT_REACH, NOT_DELETED, NOT_RESTORED, REFUSED_WITHOUT_MESSAGE, WENT_WRONG } from '@/copy/headings';
import { PAGE_STATES } from '@/copy/shell';
import { Button } from '@/components/ui/Button';
import { BulkArchiveDialog, type BulkDialogNotice } from './BulkArchiveDialog';
import type { BulkSelection } from './useBulkSelection';
import styles from './Bulk.module.css';

export type BulkMode = 'delete' | 'restore';

export interface BulkBarProps {
  bulk: BulkSelection;
  /** `delete` on the live view, `restore` in the Archived view — the caller's view decides (round-6 §2c). */
  mode: BulkMode;
  /** The list's nouns, from the caller's copy. */
  nouns: BulkNouns;
  /**
   * The served per-action cap (`BulkArchival::MAXIMUM_MEMBERS`, through the committed backend artefact once BK-5
   * exists) — a prop, never a literal in `src/` (round-6 §2c; RED H-5 item 2). Over it, the action is aria-disabled with
   * its reason visible.
   */
  maxMembers: number;
  /** `CreateBulkArchival` / `CreateBulkRestoration`, injected; called with the ids and the selection's key (C-1). */
  run: BulkRunAction;
  /**
   * CALLER CONTRACT (b4-architecture.md Revision 3 A-5): called once after a run answers `done` or `key-expired`. The
   * caller then re-reads the CURRENT page of the list; the re-read keeps the same segment, filters and search; the
   * cursor goes back to the first page if the current page is now empty. (The server never says which rows changed,
   * and the rows that did leave the view.)
   */
  onReread: () => void;
  /** The list's `<h1>` (4b-4, R1-9): focus goes there when the bar goes. */
  fallbackFocusRef: RefObject<HTMLElement | null>;
}

interface Failure {
  heading: string;
  body: ReactNode;
}

/** A non-success answer as heading + body, or null for a 401 (R1-4: no in-page copy; the fetch wrapper navigates). */
function failureFor(mode: BulkMode, result: Exclude<BulkResult, { kind: 'done' } | { kind: 'key-expired' }>, nouns: BulkNouns): Failure | null {
  const refused = mode === 'delete' ? NOT_DELETED : NOT_RESTORED;
  const interrupted = mode === 'delete' ? BULK_INTERRUPTED.deleteBody(nouns) : BULK_INTERRUPTED.restoreBody(nouns);
  switch (result.kind) {
    case 'refused':
      // R1-7 steps 3–5: no code is branched on; the server's sentence as sent (e.g. bulk.contention), else the generic.
      return { heading: refused, body: result.message !== '' ? result.message : REFUSED_WITHOUT_MESSAGE };
    case 'not-found':
      return { heading: refused, body: PAGE_STATES.notFoundInline };
    case 'forbidden':
      return { heading: refused, body: bulkForbidden(nouns) };
    case 'session-ended':
      return null;
    case 'unreachable':
      return { heading: COULD_NOT_REACH, body: interrupted };
    case 'server-error':
      return { heading: WENT_WRONG, body: interrupted };
  }
}

/**
 * The bulk bar (round-6 §2c; R4-1; b4-owed-copy.md R1-3/R1-5/R1-8/R1-9/R1-10): "{n} selected" · **Delete** (or
 * **Restore** in the Archived view) · **Clear selection**, shown while this scope has a selection. Delete asks first
 * (`BulkArchiveDialog`); Restore acts at once, as a single restore does.
 *
 * Selection after a run (R1-9): a finished run clears it (and the caller re-reads, A-5); `bulk.contention`, a lost
 * connection, a 5xx and a 403 keep it WITH ITS KEY, so pressing again replays rather than re-runs; `key-expired` clears
 * it (R1-8) and so mints no new key for the same selection. When the bar goes, focus goes to the list's `<h1>`, and
 * only then is the after-message (or the key-expired notice) written (FE-D1: the close is committed with `flushSync`
 * first; the dialog must have no exit animation, see `Dialog.module.css`). The after-message speaks through the
 * parent's `BulkResultMessage`; this component renders no live region.
 */
export function BulkBar({ bulk, mode, nouns, maxMembers, run, onReread, fallbackFocusRef }: BulkBarProps) {
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [dialogNotice, setDialogNotice] = useState<BulkDialogNotice | null>(null);

  if (!bulk.offered) return null;

  const count = bulk.count;
  const overCap = isOverCap(count, maxMembers);
  const capReason = mode === 'delete' ? BULK.deleteCapReason(maxMembers) : BULK.restoreCapReason(maxMembers);

  const submit = async () => {
    if (busy) return;
    const ids = bulk.selected;
    const key = bulk.submitKey();
    bulk.clearNotice();
    // b4-final-red M-3: a new run makes the old after-message stale, and emptying the region first means an identical
    // after-message is a real change of text, so it is announced again (R1-9's "stays until Dismiss" is between runs).
    bulk.live.clear();
    setBusy(true);
    let result: BulkResult;
    try {
      result = await run(ids, key);
    } catch {
      result = { kind: 'unreachable' };
    }
    setBusy(false);

    if (result.kind === 'done' || result.kind === 'key-expired') {
      // Focus first, then speak (FE-D1). The bar goes and, in delete mode, the dialog closes: commit that synchronously,
      // so the dialog, its focus trap and its aria-hidden on the page are gone, then move focus to the list's <h1>
      // (Dialog's pageTookFocus guard keeps it there), then announce. Restore mode has no dialog; the same order is kept
      // so both modes read alike. The re-read is still requested last, after the message.
      flushSync(() => {
        setOpen(false);
        setDialogNotice(null);
        bulk.clear();
      });
      fallbackFocusRef.current?.focus();
      if (result.kind === 'done') {
        const { applied, requested } = result;
        const head = mode === 'delete' ? BULK_AFTER.deleted(applied, requested) : BULK_AFTER.restored(applied, requested);
        const rest = requested - applied;
        const tail = rest > 0 ? plural(rest, mode === 'delete' ? BULK_AFTER.deleteRemainder : BULK_AFTER.restoreRemainder) : '';
        bulk.live.announce(tail ? `${head} ${tail}` : head);
      } else {
        bulk.showNotice({ heading: BULK_ALREADY_SENT.heading, body: BULK_ALREADY_SENT.body, focus: false, tone: 'info' });
      }
      onReread();
      return;
    }

    const failure = failureFor(mode, result, nouns);
    if (failure === null) return; // 401: the caller navigates; nothing changes here (R1-4).
    if (mode === 'delete') setDialogNotice((prev) => ({ id: (prev?.id ?? 0) + 1, ...failure }));
    else bulk.showNotice({ ...failure, focus: true, tone: 'danger' });
  };

  const clearSelection = () => {
    bulk.clear();
    fallbackFocusRef.current?.focus();
  };

  return (
    <>
      {count > 0 ? (
        <section role="region" aria-label={BULK.barRegion} className={styles.bar}>
          <span className={styles.count}>{BULK.selected(count)}</span>
          {mode === 'delete' ? (
            <Button
              size="sm"
              icon={<Archive aria-hidden="true" width={16} height={16} strokeWidth={1.5} />}
              unavailableReason={overCap ? capReason : undefined}
              onClick={() => {
                setDialogNotice(null);
                setOpen(true);
              }}
            >
              {BULK.deleteAction}
            </Button>
          ) : (
            <Button
              size="sm"
              icon={<ArchiveRestore aria-hidden="true" width={16} height={16} strokeWidth={1.5} />}
              unavailableReason={overCap ? capReason : undefined}
              loading={busy}
              onClick={() => void submit()}
            >
              {BULK.restoreAction}
            </Button>
          )}
          <Button size="sm" variant="tertiary" onClick={clearSelection}>
            {BULK.clearSelection}
          </Button>
        </section>
      ) : null}
      {mode === 'delete' ? (
        <BulkArchiveDialog
          open={open}
          onOpenChange={(next) => {
            if (!next) {
              setOpen(false);
              setDialogNotice(null);
            }
          }}
          count={count}
          nouns={nouns}
          busy={busy}
          notice={open ? dialogNotice : null}
          onConfirm={() => void submit()}
          fallbackFocusRef={fallbackFocusRef}
        />
      ) : null}
    </>
  );
}
