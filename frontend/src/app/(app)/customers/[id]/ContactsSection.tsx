'use client';

import { useCallback, useId, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { flushSync } from 'react-dom';
import { AddContactForm } from '@/components/customers/AddContactForm';
import { ContactForm } from '@/components/customers/ContactForm';
import { RegionError } from '@/components/states/PageStates';
import { Button } from '@/components/ui/Button';
import { COULD_NOT_REACH, WENT_WRONG } from '@/copy/headings';
import { ADD_CONTACT, CONTACTS_SECTION, EDIT_CONTACT } from '@/copy/customers';
import type { CustomerContact } from '@/lib/api/customers';
import { ACTION_DEADLINES_MS, startDeadline } from '@/lib/bff/deadline';
import { readOf, type ReadFailure } from '@/lib/bff/reads';
import { gateCode } from '@/lib/bff/surface';
import { loadContacts, type ContactsLoad } from '@/lib/customers/load';
import { contactCreate, contactSave } from '@/lib/customers/wired';
import { focusWasLost } from '../../ArchivableList';
import { useApiCaller } from '../../BffProvider';
import listStyles from '../../Lists.module.css';

export interface ContactsSectionProps {
  customerId: string;
  /** `ListCustomerContacts` on first render, over the server transport (the page reads it only for its holder, A-20). */
  initial: ContactsLoad;
  /** The page's CURRENT customer is archived (never the first render's value): every action goes, `archivedParent` shows. */
  customerArchived: boolean;
  /** The page's CURRENT customer's code (Add contact's title). */
  customerCode: string;
  /** Holds CreateCustomerContact's codes (from the contract): **Add contact** on a live customer, header and empty state. */
  canAdd: boolean;
  /** Holds UpdateCustomerContact's codes (from the contract): each row's **Edit** on a live customer, in an Actions column. */
  canEdit: boolean;
  /**
   * The page's counted writer for its one status region (the section has none of its own): it writes and returns the
   * write's number, whoever makes it (K-9: one counter for the whole page).
   */
  announce: (text: string) => number;
  /** Writes `text` only while write number `gen` is still the region's last (a late correction never overwrites a later sentence). */
  replaceIfCurrent: (gen: number, text: string) => void;
  /**
   * The page re-reads the customer, stamped (copy R1a-1): after a write whose customer re-read was archived, after any
   * `customer.is_archived` answer whatever the re-read said, and after a 404 on Add. What it shows follows from what it reads.
   */
  onParentStale: () => void;
}

type Reread = { kind: 'ok'; contacts: CustomerContact[] } | { kind: 'superseded' } | { kind: ReadFailure };
/** A write's re-read that did not bring a whole set: the success sentence takes its list-stale form (through `replaceIfCurrent`). */
const isStale = (k: Reread['kind']) => k === 'unreachable' || k === 'server-error' || k === 'not-found';

/**
 * The customer's contacts (customers-plan.md Revision 1 — FINAL R1-5 C-1 over Revision 0 §5, §5.1; from C-4a
 * customers-plan-c4c5.md Revision 1 R1-2 I-12a over Revision 0 I-12, F-6, F-11, F-12; `customers-c4c5-ux-copy.md` Revision
 * 1a > Revision 1 §2). A plain section on the S-3 `CatalogueSection` shape (ARCH (a): no paged list core, no view control,
 * no sort): one table over the served contacts in served order (primary first, then oldest first), columns Name · Email ·
 * Phone · Primary. A null email or phone is `none`; the Primary cell is `primaryYes` / `primaryNo` on every row, a word and
 * never a colour alone. The email and phone shown are the contact's (`customer_contact` data), under the Contacts heading
 * (plan §4, G-36). The section is `<section aria-labelledby>` its h2 (copy R1-5 L-5).
 *
 * States: an initial 403 → `denied`; any other failed read — a 404, a malformed body or a non-null cursor (the section
 * claims the whole set, plan §5: never a partial table) — → the section's own region error with `loadFailed` and Retry
 * (not focused on first paint, ST-2a); an empty answer → `empty` on a live customer, `emptyArchived` on an archived one,
 * whatever `tenant_has_rows` says. On an archived customer `archivedParent` stands above the table (or the empty
 * sentence), for everyone, and no action is offered. The count is spoken through the page's region ONLY when the first
 * successful load happens in the browser (a Retry after a failed first load); never on first paint, never on a write's
 * re-read. It is written after the focus move (FE-D1): the section's heading takes focus only when focus was lost, then the
 * count (or the empty sentence that applies, F-11) is written. A Retry press that may write the count first empties the
 * page's region (FE-R9).
 *
 * Add contact (C-4a): **Add contact** in the header — whenever the viewer may add on a live customer and the section is not
 * denied, the region-error state included — and after the empty sentence. It opens `AddContactForm`, which renders on its
 * open state alone, never on `mayAdd`: an open dialog stays open when the page turns archived, and its write then meets
 * `customer.is_archived` (M-4). On close (FE-D1, F-12): commit the close, move focus — after a 201 the Contacts heading,
 * otherwise the button that opened the dialog, else the heading — then, after a 201, speak `afterComposed` (the 201's
 * name; the primary clause only when the box was ticked AND the 201 is primary) through the page's counted `announce`,
 * then re-read. **The answered row is never committed locally** (F-6): a contact write can move the primary mark off a
 * sibling, which only a re-read can show, so the table changes only by a re-read. If that re-read fails, the list-stale
 * form replaces the sentence only through `replaceIfCurrent`, i.e. only while nothing has written the region since. When
 * the dialog's answer says the page's customer may be stale, the page re-reads it (`onParentStale`).
 *
 * Edit contact (C-4a): the row's **Edit** (accessible name `editFor(name)`, unquoted) in an Actions column shown only when
 * the viewer may edit on a live customer. It opens `ContactForm`, rendered on its open state alone (M-4). On close (FE-D1,
 * F-12): commit the close, put focus back on the row's Edit (else the heading), then, after a 200, speak `afterComposed`
 * (the 200's name; `primaryNowClause` only when `is_primary: true` was sent AND the 200 is primary, `primaryNoLongerClause`
 * when `is_primary: false` was sent), then re-read — the answered row is never committed (F-6). A re-read that removed the
 * row moves focus to the heading when it was lost. The list-stale form goes through `replaceIfCurrent` only.
 *
 * The stamp rule (the catalogue's): every read of this section takes a number when it is sent; an answer older than the
 * reading on screen is dropped, so an earlier read answered late (a Retry in flight under a write) never undoes a later one.
 */
export function ContactsSection({ customerId, initial, customerArchived, customerCode, canAdd, canEdit, announce, replaceIfCurrent, onParentStale }: ContactsSectionProps) {
  const caller = useApiCaller();
  const headingId = useId();
  const heading = useRef<HTMLHeadingElement>(null);
  const [load, setLoad] = useState<{ gen: number; value: ContactsLoad }>({ gen: 0, value: initial });
  const [retrying, setRetrying] = useState(false);
  const [focusGen, setFocusGen] = useState(0);
  const [adding, setAdding] = useState(false);
  const [editing, setEditing] = useState<CustomerContact | null>(null);
  // The button that opened the dialog, so the close can put focus back on it before anything is spoken (FE-D1).
  const opener = useRef<HTMLElement | null>(null);
  const create = useMemo(() => contactCreate(caller, customerId), [caller, customerId]);
  const save = useMemo(() => contactSave(caller, customerId), [caller, customerId]);
  const mayAdd = canAdd && !customerArchived;
  const mayEdit = canEdit && !customerArchived;
  // Whether a successful load has been on screen: the server's first read counts (first paint is not announced).
  const everLoaded = useRef(initial.kind === 'ok');
  // The sentence a successful Retry speaks, written by the layout effect after the focus move (FE-D1), never before it.
  const pendingCount = useRef<string | null>(null);
  // The stamp rule: every read takes a number when it is sent; `committedAsOf` is the number of the reading on screen.
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
   * One stamped read of the contacts. An answer older than the reading on screen is `superseded` and commits nothing. A
   * good answer or a 403 commits (in this call, `flushSync`, so the caller sees the rows it read). Any other failure commits
   * only when `replaceRows` (the Retry's region error replacing the last one); a write's re-read keeps the rows on screen.
   */
  const reread = useCallback(
    async (replaceRows: boolean): Promise<Reread> => {
      const mine = ++stamps.current;
      const d = startDeadline(ACTION_DEADLINES_MS.reread);
      const r = await loadContacts(readOf(caller), customerId, d.signal);
      d.cancel();
      if (mine < committedAsOf.current) return { kind: 'superseded' };
      // A 401 is the caller's (the session-ended notice); nothing here changes.
      if (r.kind === 'session-ended') return r;
      if (r.kind === 'ok' || r.kind === 'forbidden' || replaceRows) {
        if (r.kind === 'ok' || r.kind === 'forbidden') committedAsOf.current = mine;
        flushSync(() => setLoad((cur) => ({ gen: cur.gen + 1, value: r })));
      }
      return r.kind === 'ok' ? { kind: 'ok', contacts: r.contacts } : r;
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
      pendingCount.current = r.contacts.length === 0 ? (customerArchived ? CONTACTS_SECTION.emptyArchived : CONTACTS_SECTION.empty) : CONTACTS_SECTION.count(r.contacts.length);
    }
    setFocusGen((g) => g + 1);
  };

  const closeAdd = async ({ created, sentPrimary, reread: again, parentStale }: { created: CustomerContact | null; sentPrimary: boolean; reread: boolean; parentStale: boolean }) => {
    // FE-D1: commit the close, put focus where it goes, then speak, then re-read. After a 201: the Contacts heading.
    // Otherwise the button that opened the dialog, else — when it is gone (the page turned archived meanwhile) — the heading.
    flushSync(() => setAdding(false));
    const back = opener.current;
    opener.current = null;
    (created !== null ? heading.current : back !== null && back.isConnected ? back : heading.current)?.focus();
    // F-6: the 201 body is not committed; only the re-read changes the table. The sentence uses the 201's name.
    const primary = created !== null && sentPrimary && created.is_primary;
    const said = created !== null ? announce(ADD_CONTACT.afterComposed(created.name, { primary, stale: false })) : null;
    if (parentStale) onParentStale();
    if (!again) return;
    const r = await reread(false);
    // fe-r8-location-red L-1: what follows reads only this re-read's own answer, with nothing awaited between `reread()`
    // and the correction.
    if (created !== null && said !== null && isStale(r.kind)) replaceIfCurrent(said, ADD_CONTACT.afterComposed(created.name, { primary, stale: true }));
  };

  const closeEdit = async (edited: CustomerContact, { saved, sentPrimary, reread: again, parentStale }: { saved: CustomerContact | null; sentPrimary: boolean | undefined; reread: boolean; parentStale: boolean }) => {
    // FE-D1: commit the close, put focus back on the row's Edit button (else the heading), then speak, then re-read.
    flushSync(() => setEditing(null));
    const back = opener.current;
    opener.current = null;
    (back !== null && back.isConnected ? back : heading.current)?.focus();
    // F-6: the 200 body is not committed; only the re-read changes the table. The sentence uses the 200's name; the
    // primary clause follows what was SENT, and "now" also needs the 200 to say so (copy R1-2 row 35).
    const primary = sentPrimary === true && saved?.is_primary === true ? 'now' : sentPrimary === false ? 'no-longer' : null;
    const said = saved !== null ? announce(EDIT_CONTACT.afterComposed(saved.name, { primary, stale: false })) : null;
    if (parentStale) onParentStale();
    if (!again) return;
    const r = await reread(false);
    // fe-r8-location-red L-1: what follows reads only this re-read's own answer, with nothing awaited between `reread()`
    // and the correction.
    // The row (and its Edit, where focus went back) left with this answer (removed elsewhere): the heading.
    if (r.kind === 'ok' && !r.contacts.some((c) => c.id === edited.id) && focusWasLost()) heading.current?.focus();
    if (saved !== null && said !== null && isStale(r.kind)) replaceIfCurrent(said, EDIT_CONTACT.afterComposed(saved.name, { primary, stale: true }));
  };

  const addButton = (size?: 'sm') => (
    <Button
      size={size}
      onClick={(ev) => {
        if (adding || editing !== null) return;
        opener.current = ev.currentTarget;
        setAdding(true);
      }}
    >
      {CONTACTS_SECTION.add}
    </Button>
  );

  const v = load.value;
  return (
    <section aria-labelledby={headingId}>
      <h2 id={headingId} ref={heading} tabIndex={-1}>
        {CONTACTS_SECTION.heading}
      </h2>
      {mayAdd && v.kind !== 'forbidden' ? addButton() : null}
      {customerArchived ? <p>{CONTACTS_SECTION.archivedParent}</p> : null}
      {v.kind === 'forbidden' ? (
        <p>{CONTACTS_SECTION.denied}</p>
      ) : v.kind !== 'ok' ? (
        <RegionError key={`contacts-${load.gen}`} heading={v.kind === 'unreachable' ? COULD_NOT_REACH : WENT_WRONG} body={CONTACTS_SECTION.loadFailed} retrying={retrying} onRetry={() => void retry()} autoFocus={load.gen > 0} />
      ) : v.contacts.length === 0 ? (
        <>
          <p>{customerArchived ? CONTACTS_SECTION.emptyArchived : CONTACTS_SECTION.empty}</p>
          {mayAdd ? addButton('sm') : null}
        </>
      ) : (
        <table className={listStyles.table}>
          <thead>
            <tr>
              <th scope="col">{CONTACTS_SECTION.name}</th>
              <th scope="col">{CONTACTS_SECTION.email}</th>
              <th scope="col">{CONTACTS_SECTION.phone}</th>
              <th scope="col">{CONTACTS_SECTION.primary}</th>
              {mayEdit ? <th scope="col">{CONTACTS_SECTION.actions}</th> : null}
            </tr>
          </thead>
          <tbody>
            {v.contacts.map((c) => (
              <tr key={c.id}>
                <td>{c.name}</td>
                <td>{c.email ?? CONTACTS_SECTION.none}</td>
                <td>{c.phone ?? CONTACTS_SECTION.none}</td>
                <td>{c.is_primary ? CONTACTS_SECTION.primaryYes : CONTACTS_SECTION.primaryNo}</td>
                {mayEdit ? (
                  <td>
                    <Button
                      size="sm"
                      aria-label={CONTACTS_SECTION.editFor(c.name)}
                      onClick={(ev) => {
                        if (adding || editing !== null) return;
                        opener.current = ev.currentTarget;
                        setEditing(c);
                      }}
                    >
                      {CONTACTS_SECTION.edit}
                    </Button>
                  </td>
                ) : null}
              </tr>
            ))}
          </tbody>
        </table>
      )}
      {/* Rendered on its open state alone (never `&& mayAdd`): it stays open when the page turns archived (M-4). */}
      {editing !== null ? <ContactForm key={editing.id} contact={editing} save={save} editCode={gateCode('UpdateCustomerContact')} onClose={(o) => void closeEdit(editing, o)} fallbackFocusRef={heading} onSaveStarted={() => announce('')} /> : null}
      {adding ? <AddContactForm customerCode={customerCode} create={create} createCode={gateCode('CreateCustomerContact')} onClose={(o) => void closeAdd(o)} fallbackFocusRef={heading} onAddStarted={() => announce('')} /> : null}
    </section>
  );
}
