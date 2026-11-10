'use client';

import { useCallback, useMemo, useRef } from 'react';
import { useRouter } from 'next/navigation';
import { ItemCreateForm } from '@/components/items/ItemCreateForm';
import { NEW_ITEM } from '@/copy/items';
import type { Item } from '@/lib/api/items';
import { gateCode, wireEnum } from '@/lib/bff/surface';
import { itemCreate } from '@/lib/items/wired';
import { leaveArrivalMessage } from '../../arrivalMessage';
import { useApiCaller } from '../../BffProvider';
import listStyles from '../../Lists.module.css';

/** The served units, in served order: the create form's Unit options (BK-22; the one contract reader, G-2). */
const UNITS = wireEnum('Item.CreateItemInput', 'unit_of_measure_code');

/**
 * The create page (items-ux-copy.md B-4; items-i2-design.md Revision 1 — FINAL A-10). On `created` it leaves
 * *"{sku} was created."* (the served SKU) for the new item's page, THEN navigates there with `router.push`; that page
 * speaks it after `RouteFocus` has moved focus to its `h1`.
 */
export function NewItemPage() {
  const caller = useApiCaller();
  const router = useRouter();
  const h1 = useRef<HTMLHeadingElement>(null);
  const create = useMemo(() => itemCreate(caller), [caller]);
  const onCreated = useCallback(
    (item: Item) => {
      const href = `/items/${item.id}`;
      leaveArrivalMessage(href, NEW_ITEM.after(item.sku));
      router.push(href);
    },
    [router],
  );
  return (
    <section className={listStyles.page}>
      <header className={listStyles.header}>
        <h1 ref={h1} tabIndex={-1}>
          {NEW_ITEM.heading}
        </h1>
      </header>
      <ItemCreateForm create={create} createCode={gateCode('CreateItem')} units={UNITS} onCreated={onCreated} />
    </section>
  );
}
