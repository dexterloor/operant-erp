'use client';

import { useRef, useState, type ReactNode, type RefObject } from 'react';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import { COULD_NOT_REACH, NOT_APPROVED, REFUSED_WITHOUT_MESSAGE, WENT_WRONG } from '@/copy/headings';
import { PERMISSION_LABELS } from '@/copy/permissionLabels';
import { APPROVE_ORDER, PURCHASING_FORBIDDEN } from '@/copy/purchasing';
import { PAGE_STATES } from '@/copy/shell';
import type { PurchaseOrder } from '@/lib/api/purchasing';
import { renderMoney } from '@/lib/format/money';
import type { ApproveDialogResult } from '@/lib/purchasing/results';

export interface PlaceOrderDialogProps {
  /** The order as the page shows it when the dialog opens: its number, supplier and total (the summary) are taken once, then. */
  order: PurchaseOrder;
  /** The approval itself; never throws past here (a throw is `unreachable`). */
  run: () => Promise<ApproveDialogResult>;
  /** `gateCode('ApprovePurchaseOrder')`, for B-12's permission label on a 403 (null when the contract names none). */
  approveCode: string | null;
  /**
   * Called once: `approved` after a 200 (the caller commits the close, moves focus, speaks, refreshes — FE-D1), with the 200
   * body and the RAW served `total_amount` and `warehouse.id` the dialog opened with (M-2: the caller compares the two, the
   * 200 body only); otherwise after Go back / Escape. `reread` is true when anything was answered: the caller re-reads, so
   * what is under the dialog is the server's (an interrupted press may have committed).
   */
  onClose: (o: { approved: boolean; reread: boolean; outcome?: ApprovedOutcome }) => void;
  fallbackFocusRef: RefObject<HTMLElement | null>;
  /** Called once per Approve press that will send a request, just before it (FE-R9): the page empties its status region. */
  onApproveStarted?: () => void;
}

/** M-2: what was approved (the 200 body) beside what the dialog showed (raw served strings, never rendered ones). */
export interface ApprovedOutcome {
  body: PurchaseOrder;
  openedTotal: string;
  openedWarehouseId: string;
}

interface Notice {
  gen: number;
  heading: string;
  body: ReactNode;
}

/**
 * UX B-6 Approve's confirm dialog (plan §2.5 "Place order", §4 PO-8; IA L-6 "confirming an irreversible action"). Default
 * tone (approving is not destructive), initial focus on **Go back** (the dismiss: an irreversible action is never one key
 * away). The description is Rev 1a's `summary` — *Supplier: {supplier}*, *Deliver to: {warehouse}* (M-1 (a), placeholder) and
 * *Total: {total}* — then B-6's `body` in
 * its PO-8 form (no receive clause), all taken from the order as it was when the dialog opened. No field: the expected date
 * waits for D-4, so the request is always `{}`.
 *
 * A refusal renders inside under **Not approved**, its heading takes focus (the shared `Dialog`), and the dialog stays open.
 * `refused` (A1 DSoD-1 and A3 as sent; the page's UX D-2 sentences after A2; A6 as sent) and `not-found` remove Approve and
 * leave **Go back** only (B-6: ST-9b's Close shape); `contention` (P1) and a 403 keep Approve; an interrupted request keeps it
 * under its own heading with `interrupted`. The person who raised the order is never told before the press (ST-4f's third
 * arm): nothing here reads `created_by`. Props only; branches on `kind`, never on a code (architecture.test.ts).
 */
export function PlaceOrderDialog({ order, run, approveCode, onClose, fallbackFocusRef, onApproveStarted }: PlaceOrderDialogProps) {
  const back = useRef<HTMLButtonElement>(null);
  const bannerRef = useRef<HTMLParagraphElement>(null);
  const gen = useRef(0);
  // Taken once, when the dialog opens: a re-read committed under it (A2) must not rewrite what the person read.
  const [opened] = useState(() => ({ number: order.order_number, supplier: order.supplier.name, warehouse: order.warehouse.name, total: renderMoney(order.total_amount, order.currency_code), rawTotal: order.total_amount, warehouseId: order.warehouse.id }));
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
    onApproveStarted?.();
    setBusy(true);
    let r: ApproveDialogResult;
    try {
      r = await run();
    } catch {
      r = { kind: 'unreachable' };
    }
    setBusy(false);
    switch (r.kind) {
      case 'approved':
        onClose({ approved: true, reread: true, outcome: { body: r.order, openedTotal: opened.rawTotal, openedWarehouseId: opened.warehouseId } });
        return;
      case 'contention':
        show(NOT_APPROVED, r.message !== '' ? r.message : REFUSED_WITHOUT_MESSAGE);
        return;
      case 'refused':
        setFinal(true);
        show(NOT_APPROVED, r.message !== '' ? r.message : REFUSED_WITHOUT_MESSAGE);
        return;
      case 'forbidden': {
        const label = (approveCode !== null ? PERMISSION_LABELS[approveCode]?.label : undefined) ?? '';
        show(
          NOT_APPROVED,
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
        show(NOT_APPROVED, PAGE_STATES.notFoundInline);
        return;
      case 'unreachable':
        show(COULD_NOT_REACH, APPROVE_ORDER.interrupted(opened.number));
        return;
      case 'server-error':
        show(WENT_WRONG, APPROVE_ORDER.interrupted(opened.number));
        return;
      case 'session-ended':
        return;
    }
  };

  const dismiss = () => onClose({ approved: false, reread: answered });

  return (
    <Dialog
      open
      onOpenChange={(open) => {
        if (!open && !busy) dismiss();
      }}
      title={APPROVE_ORDER.title(opened.number)}
      description={
        <>
          <p>{APPROVE_ORDER.summarySupplier(opened.supplier)}</p>
          <p>{APPROVE_ORDER.summaryWarehouse(opened.warehouse)}</p>
          <p>{APPROVE_ORDER.summaryTotal(opened.total)}</p>
          <p>{APPROVE_ORDER.body(opened.supplier)}</p>
        </>
      }
      initialFocusRef={back}
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
      {busy ? null : (
        <Button ref={back} onClick={dismiss}>
          {APPROVE_ORDER.dismiss}
        </Button>
      )}
      {final ? null : (
        <Button variant="primary" loading={busy} onClick={() => void confirm()}>
          {APPROVE_ORDER.confirm}
        </Button>
      )}
    </Dialog>
  );
}
