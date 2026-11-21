'use client';

import { useCallback, useMemo, useRef } from 'react';
import { useRouter } from 'next/navigation';
import { SupplierCreateForm } from '@/components/suppliers/SupplierCreateForm';
import { NEW_SUPPLIER } from '@/copy/suppliers';
import type { Supplier } from '@/lib/api/suppliers';
import { gateCode } from '@/lib/bff/surface';
import { supplierCreate } from '@/lib/suppliers/wired';
import { leaveArrivalMessage } from '../../arrivalMessage';
import { useApiCaller } from '../../BffProvider';
import listStyles from '../../Lists.module.css';

/**
 * The create page (UX S-2). On `created` it leaves *"{code} was created."* for the new supplier's page (A-19 item 7) and
 * navigates there with `router.push`; that page speaks it after `RouteFocus` has moved focus to its `h1`.
 */
export function NewSupplierPage() {
  const caller = useApiCaller();
  const router = useRouter();
  const h1 = useRef<HTMLHeadingElement>(null);
  const create = useMemo(() => supplierCreate(caller), [caller]);
  const onCreated = useCallback(
    (s: Supplier) => {
      const href = `/suppliers/${s.id}`;
      leaveArrivalMessage(href, NEW_SUPPLIER.after(s.code));
      router.push(href);
    },
    [router],
  );
  return (
    <section className={listStyles.page}>
      <header className={listStyles.header}>
        <h1 ref={h1} tabIndex={-1}>
          {NEW_SUPPLIER.heading}
        </h1>
      </header>
      <SupplierCreateForm create={create} createCode={gateCode('CreateSupplier')} onCreated={onCreated} />
    </section>
  );
}
