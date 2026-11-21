'use client';

import { useId, useLayoutEffect, useRef, useState } from 'react';
import { RegionError } from '@/components/states/PageStates';
import { COULD_NOT_REACH, WENT_WRONG } from '@/copy/headings';
import { ADDRESSES_SECTION, addressKindLabel } from '@/copy/customers';
import { ACTION_DEADLINES_MS, startDeadline } from '@/lib/bff/deadline';
import { readOf } from '@/lib/bff/reads';
import { loadAddresses, type AddressesLoad } from '@/lib/customers/load';
import { focusWasLost } from '../../ArchivableList';
import { useApiCaller } from '../../BffProvider';
import listStyles from '../../Lists.module.css';

export interface AddressesSectionProps {
  customerId: string;
  /** `ListCustomerAddresses` on first render, over the server transport (the page reads it only for its holder, A-20). */
  initial: AddressesLoad;
  /** The page's one status region (the section has none of its own). */
  announce: (text: string) => void;
  /** Empties that region (FE-R9: a press that may write to it empties it first, after its guards). */
  clearRegion: () => void;
}

/**
 * The customer's addresses, read-only for everyone in C-1 (customers-plan.md Revision 1 — FINAL R1-5 C-1 over Revision 0
 * §5, §5.2; `customers-c1-ux-copy.md` Revision 1 — FINAL §3, R1-5, R1-9 `ADDRESSES_SECTION`): no Actions column, no Add,
 * Edit or Remove (they arrive at C-5). The `ContactsSection` shape: one table, columns Kind · Address · City · Postal code ·
 * Country, in the page's order from `loadAddresses` — Shipping first, then Billing, newest first within each, any other
 * kind after both and shown as sent (`addressKindLabel`). The Country cell is the served code as sent. The section is
 * `<section aria-labelledby>` its h2 (copy R1-5 L-5).
 *
 * States, Retry, the count and its timing: exactly as `ContactsSection` (403 → `denied`; a 404, a malformed body or a
 * non-null cursor → `loadFailed` with Retry; empty → `empty`, whatever `tenant_has_rows` says; no archived-parent note in
 * C-1; the count only on the first successful load after a failed one, after the focus move).
 */
export function AddressesSection({ customerId, initial, announce, clearRegion }: AddressesSectionProps) {
  const caller = useApiCaller();
  const headingId = useId();
  const heading = useRef<HTMLHeadingElement>(null);
  const [load, setLoad] = useState<{ gen: number; value: AddressesLoad }>({ gen: 0, value: initial });
  const [retrying, setRetrying] = useState(false);
  const [focusGen, setFocusGen] = useState(0);
  const everLoaded = useRef(initial.kind === 'ok');
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
    const r = await loadAddresses(readOf(caller), customerId, d.signal);
    d.cancel();
    setRetrying(false);
    if (r.kind === 'session-ended') return;
    setLoad((cur) => ({ gen: cur.gen + 1, value: r }));
    if (r.kind !== 'ok') return;
    if (!everLoaded.current) {
      everLoaded.current = true;
      pendingCount.current = r.addresses.length === 0 ? ADDRESSES_SECTION.empty : ADDRESSES_SECTION.count(r.addresses.length);
    }
    setFocusGen((g) => g + 1);
  };

  const v = load.value;
  return (
    <section aria-labelledby={headingId}>
      <h2 id={headingId} ref={heading} tabIndex={-1}>
        {ADDRESSES_SECTION.heading}
      </h2>
      {v.kind === 'forbidden' ? (
        <p>{ADDRESSES_SECTION.denied}</p>
      ) : v.kind !== 'ok' ? (
        <RegionError key={`addresses-${load.gen}`} heading={v.kind === 'unreachable' ? COULD_NOT_REACH : WENT_WRONG} body={ADDRESSES_SECTION.loadFailed} retrying={retrying} onRetry={() => void retry()} autoFocus={load.gen > 0} />
      ) : v.addresses.length === 0 ? (
        <p>{ADDRESSES_SECTION.empty}</p>
      ) : (
        <table className={listStyles.table}>
          <thead>
            <tr>
              <th scope="col">{ADDRESSES_SECTION.kind}</th>
              <th scope="col">{ADDRESSES_SECTION.line}</th>
              <th scope="col">{ADDRESSES_SECTION.city}</th>
              <th scope="col">{ADDRESSES_SECTION.postalCode}</th>
              <th scope="col">{ADDRESSES_SECTION.country}</th>
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
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}
