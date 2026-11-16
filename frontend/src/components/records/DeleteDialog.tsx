'use client';

import { useRef, useState, type ReactNode, type RefObject } from 'react';
import { TriangleAlert } from 'lucide-react';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import { COULD_NOT_REACH, NOT_DELETED, REFUSED_WITHOUT_MESSAGE, WENT_WRONG } from '@/copy/headings';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { PAGE_STATES } from '@/copy/shell';
import type { DeleteResult } from '@/lib/results';
import styles from './Records.module.css';

export interface DeleteDialogCopy {
  title: string;
  body: string;
  /**
   * Items I-3 (UX items-i3-ux.md F-1): an optional warning line, shown only when it is a non-empty string, as its own
   * paragraph BEFORE the body inside the description (so the dialog's accessible description reads it first), with a
   * warning icon (`aria-hidden`) and weight. Not a `Banner` and no heading: a banner in this dialog means a refusal, and
   * a refusal renders after the description (the shared `Dialog` order). Absent for warehouses and locations.
   */
  warning?: string;
  confirm: string;
  cancel: string;
  close: string;
  /** B-7 "Interrupted": the request's answer was lost, or the server failed. */
  interrupted: string;
  /** B-8's 403 sentence around the permission's label (the family's copy, e.g. `WAREHOUSE_FORBIDDEN`). */
  forbidden: { before: string; after: string };
  /**
   * Suppliers S-6 (suppliers-plan.md Revision 0 S-3 / Revision 1 — FINAL S-6): the refusal heading where the action is not
   * called Delete (the catalogue's **Remove** → `NOT_REMOVED`). Optional; the default is `NOT_DELETED`, so the warehouse,
   * location and item callers (and their tests) are unchanged. It heads a refusal, P1, a 403 and a 404; an interrupted
   * request keeps its own heading (`COULD_NOT_REACH` / `WENT_WRONG`). Family copy as a prop (R8-3).
   */
  refusedHeading?: string;
}

export interface DeleteDialogProps {
  copy: DeleteDialogCopy;
  /** The delete itself (one record of any family); never throws past here (a throw is `unreachable`). */
  run: () => Promise<DeleteResult>;
  /** The operation's gate code, for B-8's permission label on a 403 (null when the contract names none). */
  deleteCode: string | null;
  /**
   * Called once: `deleted` after a 204 (the caller commits the close, moves focus, speaks, refreshes — FE-D1); otherwise
   * after Cancel / Close / Escape. `reread` is true when anything was answered other than nothing at all: the caller
   * re-reads, so what is under the dialog is the server's (an interrupted press may have committed).
   */
  onClose: (o: { deleted: boolean; reread: boolean }) => void;
  fallbackFocusRef: RefObject<HTMLElement | null>;
  /**
   * Called once per Delete press that will send a request: after every guard, just before the request (FE-R9, the FE-R6
   * commit A mechanism). The page empties its status region here, in its own render before the answer, so a second
   * identical "… was deleted." is a change to the region and is given again (an equal string written over itself is no DOM change).
   */
  onDeleteStarted?: () => void;
  /**
   * Suppliers S-6 (UX `ux-s6-rulings.md` §1, adopting S-6 RED L-2 (a)): when true, an interrupted request (a lost
   * answer or a server failure) also leaves Close only, under its own heading (`COULD_NOT_REACH` / `WENT_WRONG`) with
   * `copy.interrupted`. Set by the supplier catalogue's Remove, whose interrupted sentence says "Close this and check the
   * catalogue." and must not invite a repeat that answers 404. Default false: the warehouse, location and item dialogs
   * keep Delete after an interrupted request, and their tests run unedited.
   */
  finalWhenInterrupted?: boolean;
}

interface Notice {
  gen: number;
  heading: string;
  body: ReactNode;
}

/**
 * B-7 Delete dialog (warehouses-ux-copy.md; ST-9b; Revision 7 §2 step 4.6). Danger tone, initial focus Cancel. A refusal
 * renders inside under the family's refusal heading (`refusedHeading`, default **Not deleted**), its heading takes focus (the Dialog's `refusal` id), and the dialog stays open.
 * Any refusal except P1 removes the Delete button and leaves **Close** (ST-9b; no pre-check of orders or stock, G-46).
 * P1 (`{ kind: 'contention' }`, mapped in the family's `wired.ts`) shows its sentence and keeps Delete (B-7
 * "Exception"). A 403 keeps Delete; an interrupted request keeps Delete unless the caller sets `finalWhenInterrupted`, when
 * an interrupted request leaves Close only; a 404 leaves Close only (the record can't be found, so a
 * repeat cannot succeed — 4.6 RED L-2, UX `items-i3-ux.md` §3.1). This component branches on `kind`, never on a code.
 *
 * Shared by families (Revision 8 R8-3, items plan I-0a; moved from `components/warehouses/`): props only, the family's
 * copy and result come in as props, and nothing here imports a family's `copy/` or `lib/` (architecture.test.ts).
 */
export function DeleteDialog({ copy, run, deleteCode, onClose, fallbackFocusRef, onDeleteStarted, finalWhenInterrupted = false }: DeleteDialogProps) {
  const cancel = useRef<HTMLButtonElement>(null);
  const bannerRef = useRef<HTMLParagraphElement>(null);
  const gen = useRef(0);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<Notice | null>(null);
  const [final, setFinal] = useState(false);
  const answered = notice !== null;
  const notDone = copy.refusedHeading ?? NOT_DELETED;

  const show = (heading: string, body: ReactNode) => {
    gen.current += 1;
    setNotice({ gen: gen.current, heading, body });
  };

  const confirm = async () => {
    if (busy || final) return;
    onDeleteStarted?.();
    setBusy(true);
    let r: DeleteResult;
    try {
      r = await run();
    } catch {
      r = { kind: 'unreachable' };
    }
    setBusy(false);
    switch (r.kind) {
      case 'deleted':
        onClose({ deleted: true, reread: true });
        return;
      case 'contention':
        show(notDone, r.message !== '' ? r.message : REFUSED_WITHOUT_MESSAGE);
        return;
      case 'refused':
        setFinal(true);
        show(notDone, r.message !== '' ? r.message : REFUSED_WITHOUT_MESSAGE);
        return;
      case 'forbidden': {
        const label = (deleteCode !== null ? PERMISSION_LABELS[deleteCode]?.label : undefined) ?? '';
        show(
          notDone,
          <>
            {copy.forbidden.before}
            <em>{label}</em>
            {copy.forbidden.after}
          </>,
        );
        return;
      }
      case 'not-found':
        setFinal(true);
        show(notDone, PAGE_STATES.notFoundInline);
        return;
      case 'unreachable':
        if (finalWhenInterrupted) setFinal(true);
        show(COULD_NOT_REACH, copy.interrupted);
        return;
      case 'server-error':
        if (finalWhenInterrupted) setFinal(true);
        show(WENT_WRONG, copy.interrupted);
        return;
      case 'session-ended':
        return;
    }
  };

  const dismiss = () => onClose({ deleted: false, reread: answered });

  return (
    <Dialog
      open
      onOpenChange={(open) => {
        if (!open && !busy) dismiss();
      }}
      tone="danger"
      title={copy.title}
      description={
        copy.warning !== undefined && copy.warning !== '' ? (
          <>
            <p className={styles.warning}>
              <TriangleAlert aria-hidden="true" width={16} height={16} strokeWidth={1.5} className={styles.warningIcon} />
              {copy.warning}
            </p>
            <p>{copy.body}</p>
          </>
        ) : (
          <p>{copy.body}</p>
        )
      }
      initialFocusRef={cancel}
      fallbackFocusRef={fallbackFocusRef}
      busy={busy}
      refusal={
        notice
          ? {
              id: String(notice.gen),
              headingRef: bannerRef,
              content: (
                <Banner tone="danger" live="none" heading={notice.heading} ref={bannerRef}>
                  <p>{notice.body}</p>
                </Banner>
              ),
            }
          : null
      }
    >
      {busy ? null : <Button ref={cancel} onClick={dismiss}>{final ? copy.close : copy.cancel}</Button>}
      {final ? null : (
        <Button variant="destructive" loading={busy} onClick={() => void confirm()}>
          {copy.confirm}
        </Button>
      )}
    </Dialog>
  );
}
