'use client';

import { useId, useRef, useState, type ReactNode, type RefObject } from 'react';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import { TextField } from '@/components/ui/TextField';
import { COULD_NOT_REACH, NOT_CANCELLED, REFUSED_WITHOUT_MESSAGE, WENT_WRONG } from '@/copy/headings';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { CANCEL_ORDER, PURCHASING_FORBIDDEN } from '@/copy/purchasing';
import { PAGE_STATES } from '@/copy/shell';
import type { PurchaseOrder } from '@/lib/api/purchasing';
import type { CancelDialogResult } from '@/lib/purchasing/results';
import styles from './Purchasing.module.css';

export interface CancelOrderDialogProps {
  /** The order as the page shows it when the dialog opens: its number, supplier and status (the body) are taken once, then. */
  order: PurchaseOrder;
  /** The cancel itself, with the Reason box's text as typed (`''` = none); never throws past here (a throw is `unreachable`). */
  run: (reason: string) => Promise<CancelDialogResult>;
  /** `gateCode('CancelPurchaseOrder')`, for B-12's permission label on a 403 (null when the contract names none). */
  cancelCode: string | null;
  /**
   * Called once: `cancelled` after a 200 (the caller commits the close, moves focus, speaks, refreshes — FE-D1); otherwise
   * after Keep order / Close / Escape. `reread` is true when anything was answered: the caller re-reads, so what is under
   * the dialog is the server's (an interrupted press may have committed).
   */
  onClose: (o: { cancelled: boolean; reread: boolean }) => void;
  fallbackFocusRef: RefObject<HTMLElement | null>;
  /** Called once per Cancel order press that will send a request, just before it (FE-R9): the page empties its status region. */
  onCancelStarted?: () => void;
}

interface Notice {
  gen: number;
  heading: string;
  body: ReactNode;
}

/**
 * UX B-7 Cancel order's reason dialog (IA L-6 "reason form"; plan §2.5, §4 PO-7; Rev 1a: single line). Danger tone, initial
 * focus on **Keep order** (the dismiss; never a bare "Cancel"). The body names what cancelling does, chosen from the status
 * shown when the dialog opened (ordered / partly received). One optional **Reason** (`TextField`, single line, its hint
 * says nothing in the app shows it yet — owner notice C-5); no length cap until SEC sets one (plan R-11). There is no form
 * element: Enter in the Reason sends nothing, so the irreversible action is only ever the button.
 *
 * A refusal renders inside under **Not cancelled**, its heading takes focus (the shared `Dialog`), and the dialog stays
 * open. `refused` (C1 DSoD-1 as sent; the page's UX D-2 sentences after C2; C3 as sent) and `not-found` remove Cancel order
 * and leave **Close** (ST-9b); `contention` (P1) and a 403 keep Cancel order; an interrupted request keeps it under its own
 * heading with `interrupted`. The person who raised the order is never told before the press (ST-4f's third arm): nothing
 * here reads `created_by`. Props only; branches on `kind`, never on a code (architecture.test.ts).
 */
export function CancelOrderDialog({ order, run, cancelCode, onClose, fallbackFocusRef, onCancelStarted }: CancelOrderDialogProps) {
  const keep = useRef<HTMLButtonElement>(null);
  const bannerRef = useRef<HTMLParagraphElement>(null);
  const gen = useRef(0);
  const reasonId = useId();
  // Taken once, when the dialog opens: a re-read committed under it (C2) must not rewrite what the person read.
  const [opened] = useState(() => ({ number: order.order_number, supplier: order.supplier.name, partly: order.status === 'partially_received' }));
  const [reason, setReason] = useState('');
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
    onCancelStarted?.();
    setBusy(true);
    let r: CancelDialogResult;
    try {
      r = await run(reason);
    } catch {
      r = { kind: 'unreachable' };
    }
    setBusy(false);
    switch (r.kind) {
      case 'cancelled':
        onClose({ cancelled: true, reread: true });
        return;
      case 'contention':
        show(NOT_CANCELLED, r.message !== '' ? r.message : REFUSED_WITHOUT_MESSAGE);
        return;
      case 'refused':
        setFinal(true);
        show(NOT_CANCELLED, r.message !== '' ? r.message : REFUSED_WITHOUT_MESSAGE);
        return;
      case 'forbidden': {
        const label = (cancelCode !== null ? PERMISSION_LABELS[cancelCode]?.label : undefined) ?? '';
        show(
          NOT_CANCELLED,
          <>
            {PURCHASING_FORBIDDEN.before}
            <em>{label}</em>
            {PURCHASING_FORBIDDEN.after}
          </>,
        );
        return;
      }
      case 'not-found':
        setFinal(true);
        show(NOT_CANCELLED, PAGE_STATES.notFoundInline);
        return;
      case 'unreachable':
        show(COULD_NOT_REACH, CANCEL_ORDER.interrupted(opened.number));
        return;
      case 'server-error':
        show(WENT_WRONG, CANCEL_ORDER.interrupted(opened.number));
        return;
      case 'session-ended':
        return;
    }
  };

  const dismiss = () => onClose({ cancelled: false, reread: answered });

  return (
    <Dialog
      open
      onOpenChange={(open) => {
        if (!open && !busy) dismiss();
      }}
      tone="danger"
      title={CANCEL_ORDER.title(opened.number)}
      description={<p>{opened.partly ? CANCEL_ORDER.bodyPartly(opened.supplier) : CANCEL_ORDER.bodyOrdered(opened.supplier)}</p>}
      initialFocusRef={keep}
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
      <div className={styles.dialogField}>
        <TextField id={reasonId} label={CANCEL_ORDER.reason} hint={CANCEL_ORDER.reasonHint} value={reason} onChange={(e) => setReason(e.target.value)} autoComplete="off" />
      </div>
      {busy ? null : (
        <Button ref={keep} onClick={dismiss}>
          {final ? CANCEL_ORDER.close : CANCEL_ORDER.dismiss}
        </Button>
      )}
      {final ? null : (
        <Button variant="destructive" loading={busy} onClick={() => void confirm()}>
          {CANCEL_ORDER.confirm}
        </Button>
      )}
    </Dialog>
  );
}
