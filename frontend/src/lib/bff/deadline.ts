// Per-action time limits (b3b-design.md §6.2 rule R-2 item 2; L-8): one deadline signal per user action, covering its
// request(s) and its re-read. Each figure lives here and nowhere else. A request aborted by its deadline resolves
// `unreachable`, which the surfaces render as R1-1 ("We can't tell whether…"): an aborted request may have committed.

export const ACTION_DEADLINES_MS = {
  /** Delete / Restore person, request + re-read. */
  personAction: 30_000,
  /** Confirm / Reject / Withdraw, request + re-read. */
  proposalAction: 30_000,
  /** Checklist save, request + re-read. */
  checklistSave: 30_000,
  /** Restore role: the request + the re-read after an already-restored answer. */
  roleRestore: 30_000,
  /** Save roles: every request + the re-read. */
  saveRoles: 60_000,
  /** Save a warehouse: the request + the GetWarehouse re-read. */
  warehouseSave: 30_000,
  /** Restore a warehouse: the request + the GetWarehouse re-read. */
  warehouseRestore: 30_000,
  /** Create a warehouse: the request alone (the page navigates to the new warehouse, which reads it). */
  warehouseCreate: 30_000,
  /** Add a location: the request alone (the section re-reads its view after the dialog closes). */
  locationCreate: 30_000,
  /** Save a location's code and/or kind: the request alone (the section re-reads its view after the dialog closes). */
  locationSave: 30_000,
  /** Restore a location: the request alone (the section re-reads its view after). */
  locationRestore: 30_000,
  /** Delete a warehouse: the request + the GetWarehouse re-read (the page turns archived, §2 step 4.6). */
  warehouseDelete: 30_000,
  /** Delete a location: the request alone (the section re-reads its view after the dialog closes). */
  locationDelete: 30_000,
  /** Save an item: the request + the GetItem re-read (also after a refusal, to see whether the page was stale). */
  itemSave: 30_000,
  /** Restore an item: the request + the GetItem re-read. */
  itemRestore: 30_000,
  /** Delete an item: the request + the GetItem re-read (the page turns archived; items I-3). */
  itemDelete: 30_000,
  /** Create an item: the request alone (the page navigates to the new item, which reads it). */
  itemCreate: 30_000,
  /** Save a supplier: the request + the GetSupplier re-read (also after a refusal, to see whether the page was stale). */
  supplierSave: 30_000,
  /** Restore a supplier: the request + the GetSupplier re-read. */
  supplierRestore: 30_000,
  /** Create a supplier: the request alone (the page navigates to the new supplier, which reads it). */
  supplierCreate: 30_000,
  /**
   * Save a supplier catalogue entry: the request + the GetSupplier re-read after a refusal (to see whether the supplier
   * was archived meanwhile). The section re-reads its catalogue after the dialog closes, under its own deadline.
   */
  entrySave: 30_000,
  /**
   * Add a supplier catalogue entry (S-5): the request + the GetSupplier re-read after a refusal, as for `entrySave`. The
   * item choice list's reads and the section's re-read run under `reread`.
   */
  entryCreate: 30_000,
  /**
   * Remove a supplier catalogue entry (S-6): the request + the GetSupplier re-read after a refusal, as for `entrySave`.
   * The section re-reads its catalogue after the dialog closes, under `reread`.
   */
  entryDelete: 30_000,
  /**
   * Restore a deleted purchase-order draft (PO-2): the request + the GetPurchaseOrder re-read, and, for a
   * ListPurchaseOrderLines holder, the order's live lines (they came back with it).
   */
  orderRestore: 30_000,
  /** Create a purchase-order draft (PO-3): the request alone (the page navigates to the new order, which reads it). */
  orderCreate: 30_000,
  /**
   * Save a draft purchase order's header (PO-4a): the request + the GetPurchaseOrder re-read (also after a refusal, to see
   * whether the page was stale).
   */
  orderSave: 30_000,
  /** Add a line to a draft purchase order (PO-5): the request + the GetPurchaseOrder and lines re-read. */
  lineCreate: 30_000,
  /** Delete a draft purchase order's line (PO-5): the request + the GetPurchaseOrder and lines re-read. */
  lineDelete: 30_000,
  /**
   * Delete a draft purchase order (PO-6): the request + the GetPurchaseOrder re-read (and, when it reads live — restored in
   * between — its lines); also the re-read after P9's rule, to say what the order is now. The page's re-read after the
   * dialog closes runs under `reread`.
   */
  orderDelete: 30_000,
  /** A bulk run (its re-read is outside the busy dialog, A-5). */
  bulkRun: 30_000,
  /** A re-read started by a button (Load roles again, the pager). */
  reread: 30_000,
} as const;

export interface Deadline {
  readonly signal: AbortSignal;
  /** Clears the timer; the signal is never aborted afterwards. */
  cancel(): void;
}

export function startDeadline(ms: number): Deadline {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), ms);
  return { signal: controller.signal, cancel: () => clearTimeout(timer) };
}
