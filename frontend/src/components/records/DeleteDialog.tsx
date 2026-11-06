'use client';

import { useRef, useState, type ReactNode, type RefObject } from 'react';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import { COULD_NOT_REACH, NOT_DELETED, REFUSED_WITHOUT_MESSAGE, WENT_WRONG } from '@/copy/headings';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { PAGE_STATES } from '@/copy/shell';
import type { DeleteResult } from '@/lib/results';

export interface DeleteDialogCopy {
  title: string;
  body: string;
  confirm: string;
  cancel: string;
  close: string;
  /** B-7 "Interrupted": the request's answer was lost, or the server failed. */
  interrupted: string;
  /** B-8's 403 sentence around the permission's label (the family's copy, e.g. `WAREHOUSE_FORBIDDEN`). */
  forbidden: { before: string; after: string };
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
}

interface Notice {
  gen: number;
  heading: string;
  body: ReactNode;
}

/**
 * B-7 Delete dialog (warehouses-ux-copy.md; ST-9b; Revision 7 §2 step 4.6). Danger tone, initial focus Cancel. A refusal
 * renders inside under **Not deleted**, its heading takes focus (the Dialog's `refusal` id), and the dialog stays open.
 * Any refusal except P1 removes the Delete button and leaves **Close** (ST-9b; no pre-check of orders or stock, G-46).
 * P1 (`{ kind: 'contention' }`, mapped in the family's `wired.ts`) shows its sentence and keeps Delete (B-7
 * "Exception"). A 403, 404 or an interrupted request keeps Delete too (they are not business-rule refusals). This
 * component branches on `kind`, never on a code.
 *
 * Shared by families (Revision 8 R8-3, items plan I-0a; moved from `components/warehouses/`): props only, the family's
 * copy and result come in as props, and nothing here imports a family's `copy/` or `lib/` (architecture.test.ts).
 */
export function DeleteDialog({ copy, run, deleteCode, onClose, fallbackFocusRef }: DeleteDialogProps) {
  const cancel = useRef<HTMLButtonElement>(null);
  const bannerRef = useRef<HTMLParagraphElement>(null);
  const gen = useRef(0);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<Notice | null>(null);
  const [final, setFinal] = useState(false);
  const answered = notice !== null;

  const show = (heading: string, body: ReactNode) => {
    gen.current += 1;
    setNotice({ gen: gen.current, heading, body });
  };

  const confirm = async () => {
    if (busy || final) return;
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
        show(NOT_DELETED, r.message !== '' ? r.message : REFUSED_WITHOUT_MESSAGE);
        return;
      case 'refused':
        setFinal(true);
        show(NOT_DELETED, r.message !== '' ? r.message : REFUSED_WITHOUT_MESSAGE);
        return;
      case 'forbidden': {
        const label = (deleteCode !== null ? PERMISSION_LABELS[deleteCode]?.label : undefined) ?? '';
        show(
          NOT_DELETED,
          <>
            {copy.forbidden.before}
            <em>{label}</em>
            {copy.forbidden.after}
          </>,
        );
        return;
      }
      case 'not-found':
        show(NOT_DELETED, PAGE_STATES.notFoundInline);
        return;
      case 'unreachable':
        show(COULD_NOT_REACH, copy.interrupted);
        return;
      case 'server-error':
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
      description={<p>{copy.body}</p>}
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
