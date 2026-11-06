'use client';

import { useCallback, useMemo, useRef } from 'react';
import { useRouter } from 'next/navigation';
import { WarehouseCreateForm } from '@/components/warehouses/WarehouseCreateForm';
import { NEW_WAREHOUSE } from '@/copy/warehouses';
import type { Warehouse } from '@/lib/api/warehouses';
import { gateCode } from '@/lib/bff/surface';
import { warehouseCreate } from '@/lib/warehouses/wired';
import { leaveArrivalMessage } from '../../arrivalMessage';
import { useApiCaller } from '../../BffProvider';
import listStyles from '../../Lists.module.css';

/**
 * The create page (B-4). On `created` it leaves *"{code} was created."* for the new warehouse's page (A-19 item 7) and
 * navigates there with `router.push`; that page speaks it after `RouteFocus` has moved focus to its `h1`.
 */
export function NewWarehousePage() {
  const caller = useApiCaller();
  const router = useRouter();
  const h1 = useRef<HTMLHeadingElement>(null);
  const create = useMemo(() => warehouseCreate(caller), [caller]);
  const onCreated = useCallback(
    (w: Warehouse) => {
      const href = `/warehouses/${w.id}`;
      leaveArrivalMessage(href, NEW_WAREHOUSE.after(w.code));
      router.push(href);
    },
    [router],
  );
  return (
    <section className={listStyles.page}>
      <header className={listStyles.header}>
        <h1 ref={h1} tabIndex={-1}>
          {NEW_WAREHOUSE.heading}
        </h1>
      </header>
      <WarehouseCreateForm create={create} createCode={gateCode('CreateWarehouse')} onCreated={onCreated} />
    </section>
  );
}
