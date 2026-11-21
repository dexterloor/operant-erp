'use client';

import { useId, useLayoutEffect, useRef, useState } from 'react';
import { RegionError } from '@/components/states/PageStates';
import { COULD_NOT_REACH, WENT_WRONG } from '@/copy/headings';
import { CONTACTS_SECTION } from '@/copy/customers';
import { ACTION_DEADLINES_MS, startDeadline } from '@/lib/bff/deadline';
import { readOf } from '@/lib/bff/reads';
import { loadContacts, type ContactsLoad } from '@/lib/customers/load';
import { focusWasLost } from '../../ArchivableList';
import { useApiCaller } from '../../BffProvider';
import listStyles from '../../Lists.module.css';

export interface ContactsSectionProps {
  customerId: string;
  /** `ListCustomerContacts` on first render, over the server transport (the page reads it only for its holder, A-20). */
  initial: ContactsLoad;
  /** The page's one status region (the section has none of its own). */
  announce: (text: string) => void;
  /** Empties that region (FE-R9: a press that may write to it empties it first, after its guards). */
  clearRegion: () => void;
}

/**
 * The customer's contacts, read-only for everyone in C-1 (customers-plan.md Revision 1 — FINAL R1-5 C-1 over Revision 0
 * §5, §5.1; `customers-c1-ux-copy.md` Revision 1 — FINAL §2, R1-5, R1-9 `CONTACTS_SECTION`): no Actions column, no Add,
 * Edit or Remove (they arrive at C-4). A plain section on the S-3 `CatalogueSection` shape (ARCH (a): no paged list core,
 * no view control, no sort): one table over the served contacts in served order (primary first, then oldest first),
 * columns Name · Email · Phone · Primary. A null email or phone is `none`; the Primary cell is `primaryYes` / `primaryNo`
 * on every row, a word and never a colour alone. The email and phone shown are the contact's (`customer_contact` data),
 * under the Contacts heading (plan §4, G-36). The section is `<section aria-labelledby>` its h2 (copy R1-5 L-5).
 *
 * States: an initial 403 → `denied`; any other failed read — a 404, a malformed body or a non-null cursor (the section
 * claims the whole set, plan §5: never a partial table) — → the section's own region error with `loadFailed` and Retry
 * (not focused on first paint, ST-2a); an empty answer → `empty`, whatever `tenant_has_rows` says (copy R1-5 L-2). No
 * archived-parent note in C-1: the page's ST-9 banner says the customer is archived (copy R1-4). The count is spoken
 * through the page's region ONLY when the first successful load happens in the browser (a Retry after a failed first
 * load); never on first paint. It is written after the focus move (FE-D1): the section's heading takes focus only when focus
 * was lost, then the count (or `empty`, when the Retry brings none) is written. A Retry press that may write the count
 * first empties the page's region (FE-R9). The section's only read is Retry, which ignores presses while one is in flight,
 * so no stamp is needed; with nothing written on this page in C-1 there is no write counter (it lands with C-2a or C-4).
 */
export function ContactsSection({ customerId, initial, announce, clearRegion }: ContactsSectionProps) {
  const caller = useApiCaller();
  const headingId = useId();
  const heading = useRef<HTMLHeadingElement>(null);
  const [load, setLoad] = useState<{ gen: number; value: ContactsLoad }>({ gen: 0, value: initial });
  const [retrying, setRetrying] = useState(false);
  const [focusGen, setFocusGen] = useState(0);
  // Whether a successful load has been on screen: the server's first read counts (first paint is not announced).
  const everLoaded = useRef(initial.kind === 'ok');
  // The sentence a successful Retry speaks, written by the layout effect after the focus move (FE-D1), never before it.
  const pendingCount = useRef<string | null>(null);

  useLayoutEffect(() => {
    if (focusGen === 0) return;
    if (focusWasLost()) heading.current?.focus();
    if (pendingCount.current !== null) {
      announce(pendingCount.current);
      pendingCount.current = null;
    }
  }, [focusGen, announce]);

  const retry = async () => {
    if (retrying) return;
    if (!everLoaded.current) clearRegion();
    setRetrying(true);
    const d = startDeadline(ACTION_DEADLINES_MS.reread);
    const r = await loadContacts(readOf(caller), customerId, d.signal);
    d.cancel();
    setRetrying(false);
    // A 401 is the caller's (the session-ended notice); nothing here changes.
    if (r.kind === 'session-ended') return;
    setLoad((cur) => ({ gen: cur.gen + 1, value: r }));
    if (r.kind !== 'ok') return;
    if (!everLoaded.current) {
      everLoaded.current = true;
      pendingCount.current = r.contacts.length === 0 ? CONTACTS_SECTION.empty : CONTACTS_SECTION.count(r.contacts.length);
    }
    setFocusGen((g) => g + 1);
  };

  const v = load.value;
  return (
    <section aria-labelledby={headingId}>
      <h2 id={headingId} ref={heading} tabIndex={-1}>
        {CONTACTS_SECTION.heading}
      </h2>
      {v.kind === 'forbidden' ? (
        <p>{CONTACTS_SECTION.denied}</p>
      ) : v.kind !== 'ok' ? (
        <RegionError key={`contacts-${load.gen}`} heading={v.kind === 'unreachable' ? COULD_NOT_REACH : WENT_WRONG} body={CONTACTS_SECTION.loadFailed} retrying={retrying} onRetry={() => void retry()} autoFocus={load.gen > 0} />
      ) : v.contacts.length === 0 ? (
        <p>{CONTACTS_SECTION.empty}</p>
      ) : (
        <table className={listStyles.table}>
          <thead>
            <tr>
              <th scope="col">{CONTACTS_SECTION.name}</th>
              <th scope="col">{CONTACTS_SECTION.email}</th>
              <th scope="col">{CONTACTS_SECTION.phone}</th>
              <th scope="col">{CONTACTS_SECTION.primary}</th>
            </tr>
          </thead>
          <tbody>
            {v.contacts.map((c) => (
              <tr key={c.id}>
                <td>{c.name}</td>
                <td>{c.email ?? CONTACTS_SECTION.none}</td>
                <td>{c.phone ?? CONTACTS_SECTION.none}</td>
                <td>{c.is_primary ? CONTACTS_SECTION.primaryYes : CONTACTS_SECTION.primaryNo}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}
