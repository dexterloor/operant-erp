'use client';

import Link from 'next/link';
import { useId, useState } from 'react';
import { STOCK_SCOPE } from '@/copy/stock';
import { kindLabel } from '@/copy/warehouses';
import listStyles from '../Lists.module.css';
import styles from './Stock.module.css';

// Step 2's location links and their search box (stock-st2-plan.md §2.2; UX `stock-st2-ux-copy.md` §B-2, §B-10). The box
// narrows the links it was handed, by a prefix of the code, whatever the capitals, keeping the served order. It NEVER
// writes the address and never sends a request (R11-2): the links are the only way on. The kind's label is the warehouse
// family's vocabulary under one named copy exception (Revision 12 R12-5). The page keys this
// component by the warehouse, so the search text never outlives its address (R12-3).

export interface LocationLink {
  readonly id: string;
  readonly code: string;
  readonly kind: string;
  readonly href: string;
}

export function LocationLinks({ links }: { links: readonly LocationLink[] }) {
  const [q, setQ] = useState('');
  const id = useId();
  const typed = q.toLowerCase();
  const shown = typed === '' ? links : links.filter((l) => l.code.toLowerCase().startsWith(typed));
  return (
    <div className={styles.find}>
      <div className={listStyles.search}>
        <label htmlFor={id}>{STOCK_SCOPE.findLocation}</label>
        <input id={id} className={listStyles.searchInput} type="search" value={q} onChange={(e) => setQ(e.currentTarget.value)} autoComplete="off" />
      </div>
      {shown.length === 0 ? (
        <p className={listStyles.emptyHeading}>
          {STOCK_SCOPE.findNoMatch(q)}{' '}
          <button type="button" className={styles.linkButton} onClick={() => setQ('')}>
            {STOCK_SCOPE.clearFind}
          </button>
        </p>
      ) : (
        <ul className={styles.choices}>
          {shown.map((l) => (
            <li key={l.id}>
              <Link className={styles.choice} href={l.href}>
                {STOCK_SCOPE.locationOption(l.code, kindLabel(l.kind))}
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
