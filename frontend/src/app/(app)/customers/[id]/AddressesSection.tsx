'use client';

import { useCallback, useId, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { flushSync } from 'react-dom';
import { AddAddressForm } from '@/components/customers/AddAddressForm';
import { AddressForm } from '@/components/customers/AddressForm';
import { DeleteDialog } from '@/components/records/DeleteDialog';
import { RegionError } from '@/components/states/PageStates';
import { Button } from '@/components/ui/Button';
import { COULD_NOT_REACH, NOT_REMOVED, WENT_WRONG } from '@/copy/headings';
import { ADD_ADDRESS, ADDRESSES_SECTION, CUSTOMER_FORBIDDEN, EDIT_ADDRESS, REMOVE_ADDRESS, addressKindLabel } from '@/copy/customers';
import type { CustomerAddress } from '@/lib/api/customers';
import { ACTION_DEADLINES_MS, startDeadline } from '@/lib/bff/deadline';
import { readOf, type ReadFailure } from '@/lib/bff/reads';
import { gateCode, wireEnum } from '@/lib/bff/surface';
import { orderKinds } from '@/lib/customers/addressKinds';
import { loadAddresses, type AddressesLoad } from '@/lib/customers/load';
import { addressCreate, addressDelete, addressSave } from '@/lib/customers/wired';
import type { DeleteResult } from '@/lib/results';
import { focusWasLost } from '../../ArchivableList';
import { useApiCaller } from '../../BffProvider';
import listStyles from '../../Lists.module.css';

const CREATE_INPUT = 'CustomerAddress.CreateCustomerAddressInput';
const UPDATE_INPUT = 'CustomerAddress.UpdateCustomerAddressInput.jsonMergePatch';

export interface AddressesSectionProps {
  customerId: string;
  /** `ListCustomerAddresses` on first render, over the server transport (the page reads it only for its holder, A-20). */
  initial: AddressesLoad;
  /** The page's CURRENT customer is archived (never the first render's value): every action goes, `archivedParent` shows. */
  customerArchived: boolean;
  /** The page's CURRENT customer's code (Add address's title). */
  customerCode: string;
  /** Holds CreateCustomerAddress's codes (from the contract): **Add address** on a live customer, header and empty state. */
  canAdd: boolean;
  /** Holds UpdateCustomerAddress's codes (from the contract): each row's **Edit** on a live customer, in an Actions column. */
  canEdit: boolean;
  /** Holds DeleteCustomerAddress's codes (from the contract): each row's **Remove** on a live customer, in the Actions column (C-5b). */
  canRemove: boolean;
  /**
   * The page's counted writer for its one status region (the section has none of its own): it writes and returns the
   * write's number, whoever makes it (K-9: one counter for the whole page, shared with the Contacts section).
   */
  announce: (text: string) => number;
  /** Writes `text` only while write number `gen` is still the region's last (a late correction never overwrites a later sentence). */
  replaceIfCurrent: (gen: number, text: string) => void;
  /**
   * The page re-reads the customer, stamped (copy R1a-1): after a write whose customer re-read was archived, after any
   * `customer.is_archived` answer whatever the re-read said, and after a 404 on Add.
   */
  onParentStale: () => void;
}

type Reread = { kind: 'ok'; addresses: CustomerAddress[] } | { kind: 'superseded' } | { kind: ReadFailure };
/** A write's re-read that did not bring a whole set: the success sentence takes its list-stale form (through `replaceIfCurrent`). */
const isStale = (k: Reread['kind']) => k === 'unreachable' || k === 'server-error' || k === 'not-found';

/**
 * The customer's addresses (customers-plan.md Revision 1 — FINAL R1-5 C-1 over Revision 0 §5, §5.2; from C-5a
 * customers-plan-c4c5.md Revision 1 R1-4 over Revision 0 J-9, F-6, F-11, F-12; `customers-c4c5-ux-copy.md` Revision 1a >
 * Revision 1 §3). The `ContactsSection` shape: one table, columns Kind · Address · City · Postal code · Country, in the
 * page's order from `loadAddresses` — Shipping first, then Billing, newest first within each, any other kind after both
 * and shown as sent (`addressKindLabel`). The Country cell is the served code as sent. The section is
 * `<section aria-labelledby>` its h2 (copy R1-5 L-5).
 *
 * States, Retry, the count and its timing: exactly as `ContactsSection` (403 → `denied`; a 404, a malformed body or a
 * non-null cursor → `loadFailed` with Retry; empty → `empty` on a live customer, `emptyArchived` on an archived one; on an
 * archived customer `archivedParent` stands above the table or the empty sentence, for everyone, and no action is offered;
 * the count only on the first successful load after a failed one, after the focus move).
 *
 * Add address (C-5a): **Add address** in the header — whenever the viewer may add on a live customer and the section is
 * not denied, the region-error state included — and after the empty sentence. It opens `AddAddressForm`, rendered on its
 * open state alone, never on `mayAdd` (M-4). On close (FE-D1, F-12): commit the close, move focus — after a 201 the
 * Addresses heading, otherwise the button that opened the dialog, else the heading — then, after a 201, speak
 * `ADD_ADDRESS.afterComposed` (the 201's kind and line) through the page's counted `announce`, then re-read. **The
 * answered row is never committed locally** (F-6): the table changes only by a re-read, so the page's order comes from
 * `orderAddresses`. If that re-read fails, the list-stale form replaces the sentence only through `replaceIfCurrent`.
 * When the dialog's answer says the page's customer may be stale, the page re-reads it (`onParentStale`).
 *
 * Edit address (C-5a): the row's **Edit** (accessible name `editFor(kind, line)`, unquoted) in an Actions column shown
 * only when the viewer may edit (from C-5b: or remove) on a live customer. It opens `AddressForm`, rendered on its open state alone (M-4). On
 * close: commit the close, put focus back on the row's Edit (else the heading), then, after a 200, speak
 * `EDIT_ADDRESS.afterComposed` (the 200's kind and line), then re-read — the answered row is never committed (F-6). A
 * re-read that removed the row moves focus to the heading when it was lost. The list-stale form goes through
 * `replaceIfCurrent` only.
 *
 * Remove address (C-5b, customers-plan-c4c5.md Revision 0 J-4, J-9 as fixed by customers-plan-c4b-c5-final-red.md; the
 * `ContactsSection` Remove shape): the row's **Remove** (accessible name `removeFor(kind, line)`, unquoted), in the Actions
 * column, shown when the viewer may Edit OR Remove on a live customer. It opens the shared Delete dialog with
 * `REMOVE_ADDRESS`'s copy, the heading **Not removed**, `CUSTOMER_FORBIDDEN` around `DeleteCustomerAddress`'s permission
 * on a 403, Close only after an interrupted press; rendered on its open state alone, never on `mayRemove` (M-4). A Remove
 * whose customer re-read was archived, or whose rule was `customer.is_archived`, shows its client sentence as the dialog's
 * refusal (Close only), and the dialog's going has the page re-read the customer (`onParentStale`, a flag read and
 * cleared on every close, R1a-1). On close (FE-D1, F-12): commit the close, move focus — after a 204 the Addresses
 * heading, otherwise the row's Remove, else the heading — then, after a 204, drop the row under a FRESH stamp (F-6: the
 * 204 is the server's word) and speak `after`, then re-read. A re-read that removed the row moves focus to the heading when
 * it was lost; the list-stale form goes through `replaceIfCurrent` only. The region is emptied as the press is sent
 * (FE-R9), so a second identical sentence is spoken.
 *
 * The stamp rule (the catalogue's): every read of this section takes a number when it is sent; an answer older than the
 * reading on screen is dropped, so an earlier read answered late (a Retry in flight under a write) never undoes a later one.
 */
export function AddressesSection({ customerId, initial, customerArchived, customerCode, canAdd, canEdit, canRemove, announce, replaceIfCurrent, onParentStale }: AddressesSectionProps) {
  const caller = useApiCaller();
  const headingId = useId();
  const heading = useRef<HTMLHeadingElement>(null);
  const [load, setLoad] = useState<{ gen: number; value: AddressesLoad }>({ gen: 0, value: initial });
  const [retrying, setRetrying] = useState(false);
  const [focusGen, setFocusGen] = useState(0);
  const [adding, setAdding] = useState(false);
  const [editing, setEditing] = useState<CustomerAddress | null>(null);
  const [removing, setRemoving] = useState<CustomerAddress | null>(null);
  // Remove: whether the open dialog's answer says the page's customer may be stale (read and cleared on every close).
  const removeParentStale = useRef(false);
  // The button that opened the dialog, so the close can put focus back on it before anything is spoken (FE-D1).
  const opener = useRef<HTMLElement | null>(null);
  const create = useMemo(() => addressCreate(caller, customerId), [caller, customerId]);
  const save = useMemo(() => addressSave(caller, customerId), [caller, customerId]);
  const remove = useMemo(() => addressDelete(caller, customerId), [caller, customerId]);
  const mayAdd = canAdd && !customerArchived;
  const mayEdit = canEdit && !customerArchived;
  const mayRemove = canRemove && !customerArchived;
  const everLoaded = useRef(initial.kind === 'ok');
  const pendingCount = useRef<string | null>(null);
  const stamps = useRef(0);
  const committedAsOf = useRef(0);

  useLayoutEffect(() => {
    if (focusGen === 0) return;
    if (focusWasLost()) heading.current?.focus();
    if (pendingCount.current !== null) {
      announce(pendingCount.current);
      pendingCount.current = null;
    }
  }, [focusGen, announce]);

  /**
   * One stamped read of the addresses (the `ContactsSection` shape). An answer older than the reading on screen is
   * `superseded` and commits nothing. A good answer or a 403 commits (in this call, `flushSync`); any other failure commits
   * only when `replaceRows` (the Retry's region error replacing the last one).
   */
  const reread = useCallback(
    async (replaceRows: boolean): Promise<Reread> => {
      const mine = ++stamps.current;
      const d = startDeadline(ACTION_DEADLINES_MS.reread);
      const r = await loadAddresses(readOf(caller), customerId, d.signal);
      d.cancel();
      if (mine < committedAsOf.current) return { kind: 'superseded' };
      if (r.kind === 'session-ended') return r;
      if (r.kind === 'ok' || r.kind === 'forbidden' || replaceRows) {
        if (r.kind === 'ok' || r.kind === 'forbidden') committedAsOf.current = mine;
        flushSync(() => setLoad((cur) => ({ gen: cur.gen + 1, value: r })));
      }
      return r.kind === 'ok' ? { kind: 'ok', addresses: r.addresses } : r;
    },
    [caller, customerId],
  );

  const retry = async () => {
    if (retrying) return;
    if (!everLoaded.current) announce('');
    setRetrying(true);
    const r = await reread(true);
    setRetrying(false);
    if (r.kind !== 'ok') return;
    if (!everLoaded.current) {
      everLoaded.current = true;
      // F-11: the spoken empty sentence follows the shown one (live `empty`, archived `emptyArchived`).
      pendingCount.current = r.addresses.length === 0 ? (customerArchived ? ADDRESSES_SECTION.emptyArchived : ADDRESSES_SECTION.empty) : ADDRESSES_SECTION.count(r.addresses.length);
    }
    setFocusGen((g) => g + 1);
  };

  const closeAdd = async ({ created, reread: again, parentStale }: { created: CustomerAddress | null; reread: boolean; parentStale: boolean }) => {
    // FE-D1: commit the close, put focus where it goes, then speak, then re-read. After a 201: the Addresses heading.
    flushSync(() => setAdding(false));
    const back = opener.current;
    opener.current = null;
    (created !== null ? heading.current : back !== null && back.isConnected ? back : heading.current)?.focus();
    // F-6: the 201 body is not committed; only the re-read changes the table. The sentence uses the 201's kind and line.
    const said = created !== null ? announce(ADD_ADDRESS.afterComposed(created.kind, created.line, { stale: false })) : null;
    if (parentStale) onParentStale();
    if (!again) return;
    const r = await reread(false);
    // fe-r8-location-red L-1: what follows reads only this re-read's own answer, with nothing awaited between `reread()`
    // and the correction.
    if (created !== null && said !== null && isStale(r.kind)) replaceIfCurrent(said, ADD_ADDRESS.afterComposed(created.kind, created.line, { stale: true }));
  };

  const closeEdit = async (edited: CustomerAddress, { saved, reread: again, parentStale }: { saved: CustomerAddress | null; reread: boolean; parentStale: boolean }) => {
    // FE-D1: commit the close, put focus back on the row's Edit button (else the heading), then speak, then re-read.
    flushSync(() => setEditing(null));
    const back = opener.current;
    opener.current = null;
    (back !== null && back.isConnected ? back : heading.current)?.focus();
    // F-6: the 200 body is not committed; only the re-read changes the table. The sentence uses the 200's kind and line.
    const said = saved !== null ? announce(EDIT_ADDRESS.afterComposed(saved.kind, saved.line, { stale: false })) : null;
    if (parentStale) onParentStale();
    if (!again) return;
    const r = await reread(false);
    // fe-r8-location-red L-1: what follows reads only this re-read's own answer, with nothing awaited between `reread()`
    // and the correction.
    if (r.kind === 'ok' && !r.addresses.some((a) => a.id === edited.id) && focusWasLost()) heading.current?.focus();
    if (saved !== null && said !== null && isStale(r.kind)) replaceIfCurrent(said, EDIT_ADDRESS.afterComposed(saved.kind, saved.line, { stale: true }));
  };

  /** One Remove press: an archived answer is shown as the shared dialog's refusal (Close only) and remembered for the close. */
  const runRemove = async (a: CustomerAddress): Promise<DeleteResult> => {
    const r = await remove(a.id);
    switch (r.kind) {
      case 'refused-archived':
        removeParentStale.current = true;
        return { kind: 'refused', rule: r.rule, message: REMOVE_ADDRESS.archived };
      case 'archived-then-restored':
        removeParentStale.current = true;
        return { kind: 'refused', rule: r.rule, message: REMOVE_ADDRESS.archivedThenRestored };
      case 'archived-at-remove':
        removeParentStale.current = true;
        return { kind: 'refused', rule: r.rule, message: REMOVE_ADDRESS.archivedAtRemove };
      default:
        return r;
    }
  };

  const closeRemove = async (removed: CustomerAddress, { deleted, reread: again }: { deleted: boolean; reread: boolean }) => {
    // FE-D1: commit the close, put focus where it goes, then speak, then re-read. After a 204 the row (and its Remove)
    // goes, so the Addresses heading; otherwise the row's Remove, else — when it is gone — the heading.
    flushSync(() => setRemoving(null));
    const back = opener.current;
    opener.current = null;
    (deleted ? heading.current : back !== null && back.isConnected ? back : heading.current)?.focus();
    const parentStale = removeParentStale.current;
    removeParentStale.current = false;
    let said: number | null = null;
    if (deleted) {
      // The 204 is the server's word that the address is gone: drop the row under a FRESH stamp, so a re-read in flight
      // from before this Remove, answered after it, cannot bring it back.
      committedAsOf.current = ++stamps.current;
      flushSync(() => setLoad((cur) => (cur.value.kind === 'ok' ? { gen: cur.gen + 1, value: { ...cur.value, addresses: cur.value.addresses.filter((x) => x.id !== removed.id) } } : cur)));
      said = announce(REMOVE_ADDRESS.after(removed.kind, removed.line));
    }
    if (parentStale) onParentStale();
    if (!again) return;
    const r = await reread(false);
    // fe-r8-location-red L-1: what follows reads only this re-read's own answer, with nothing awaited between `reread()`
    // and the correction.
    // The row (and its Remove, where focus went back) left with this answer (removed elsewhere, a 404): the heading.
    if (r.kind === 'ok' && !r.addresses.some((x) => x.id === removed.id) && focusWasLost()) heading.current?.focus();
    if (said !== null && isStale(r.kind)) replaceIfCurrent(said, REMOVE_ADDRESS.afterListStale(removed.kind, removed.line));
  };

  const addButton = (size?: 'sm') => (
    <Button
      size={size}
      onClick={(ev) => {
        if (adding || editing !== null || removing !== null) return;
        opener.current = ev.currentTarget;
        setAdding(true);
      }}
    >
      {ADDRESSES_SECTION.add}
    </Button>
  );

  const v = load.value;
  return (
    <section aria-labelledby={headingId}>
      <h2 id={headingId} ref={heading} tabIndex={-1}>
        {ADDRESSES_SECTION.heading}
      </h2>
      {mayAdd && v.kind !== 'forbidden' ? addButton() : null}
      {customerArchived ? <p>{ADDRESSES_SECTION.archivedParent}</p> : null}
      {v.kind === 'forbidden' ? (
        <p>{ADDRESSES_SECTION.denied}</p>
      ) : v.kind !== 'ok' ? (
        <RegionError key={`addresses-${load.gen}`} heading={v.kind === 'unreachable' ? COULD_NOT_REACH : WENT_WRONG} body={ADDRESSES_SECTION.loadFailed} retrying={retrying} onRetry={() => void retry()} autoFocus={load.gen > 0} />
      ) : v.addresses.length === 0 ? (
        <>
          <p>{customerArchived ? ADDRESSES_SECTION.emptyArchived : ADDRESSES_SECTION.empty}</p>
          {mayAdd ? addButton('sm') : null}
        </>
      ) : (
        <table className={listStyles.table}>
          <thead>
            <tr>
              <th scope="col">{ADDRESSES_SECTION.kind}</th>
              <th scope="col">{ADDRESSES_SECTION.line}</th>
              <th scope="col">{ADDRESSES_SECTION.city}</th>
              <th scope="col">{ADDRESSES_SECTION.postalCode}</th>
              <th scope="col">{ADDRESSES_SECTION.country}</th>
              {mayEdit || mayRemove ? <th scope="col">{ADDRESSES_SECTION.actions}</th> : null}
            </tr>
          </thead>
          <tbody>
            {v.addresses.map((a) => (
              <tr key={a.id}>
                <td>{addressKindLabel(a.kind)}</td>
                <td>{a.line}</td>
                <td>{a.city}</td>
                <td>{a.postal_code}</td>
                <td>{a.country_code}</td>
                {mayEdit || mayRemove ? (
                  <td>
                    {mayEdit ? (
                      <Button
                        size="sm"
                        aria-label={ADDRESSES_SECTION.editFor(a.kind, a.line)}
                        onClick={(ev) => {
                          if (adding || editing !== null || removing !== null) return;
                          opener.current = ev.currentTarget;
                          setEditing(a);
                        }}
                      >
                        {ADDRESSES_SECTION.edit}
                      </Button>
                    ) : null}
                    {mayRemove ? (
                      <Button
                        size="sm"
                        variant="destructive"
                        aria-label={ADDRESSES_SECTION.removeFor(a.kind, a.line)}
                        onClick={(ev) => {
                          if (adding || editing !== null || removing !== null) return;
                          opener.current = ev.currentTarget;
                          setRemoving(a);
                        }}
                      >
                        {ADDRESSES_SECTION.remove}
                      </Button>
                    ) : null}
                  </td>
                ) : null}
              </tr>
            ))}
          </tbody>
        </table>
      )}
      {/* Rendered on its open state alone (never `&& mayEdit`): it stays open when the page turns archived (M-4). */}
      {editing !== null ? <AddressForm key={editing.id} address={editing} kinds={orderKinds(wireEnum(UPDATE_INPUT, 'kind'))} save={save} editCode={gateCode('UpdateCustomerAddress')} onClose={(o) => void closeEdit(editing, o)} fallbackFocusRef={heading} onSaveStarted={() => announce('')} /> : null}
      {/* Rendered on its open state alone (never `&& mayRemove`): it stays open when the page turns archived (M-4). */}
      {removing !== null ? (
        <DeleteDialog
          key={removing.id}
          copy={{
            title: REMOVE_ADDRESS.title(removing.kind, removing.line),
            body: REMOVE_ADDRESS.body,
            confirm: REMOVE_ADDRESS.confirm,
            cancel: REMOVE_ADDRESS.cancel,
            close: REMOVE_ADDRESS.close,
            interrupted: REMOVE_ADDRESS.interrupted(removing.kind, removing.line),
            forbidden: CUSTOMER_FORBIDDEN,
            refusedHeading: NOT_REMOVED,
          }}
          run={() => runRemove(removing)}
          deleteCode={gateCode('DeleteCustomerAddress')}
          onClose={(o) => void closeRemove(removing, o)}
          fallbackFocusRef={heading}
          onDeleteStarted={() => announce('')}
          // copy row 97 (D-14): an interrupted Remove leaves Close only.
          finalWhenInterrupted
        />
      ) : null}
      {/* Rendered on its open state alone (never `&& mayAdd`): it stays open when the page turns archived (M-4). */}
      {adding ? <AddAddressForm customerCode={customerCode} kinds={orderKinds(wireEnum(CREATE_INPUT, 'kind'))} create={create} createCode={gateCode('CreateCustomerAddress')} onClose={(o) => void closeAdd(o)} fallbackFocusRef={heading} onAddStarted={() => announce('')} /> : null}
    </section>
  );
}
