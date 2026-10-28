'use client';

import { pageCheckState } from '@/lib/bulk/selection';
import { BULK } from '@/copy/bulk';
import { Checkbox } from '@/components/ui/Checkbox';
import type { BulkSelection } from './useBulkSelection';
import styles from './Bulk.module.css';

/**
 * The checkbox column's header cell (round-6 §2c): selects or unselects the rows on THIS page only — mixed when some are
 * selected. Renders nothing (no column) unless the caller offered bulk (`useBulkSelection({ offered })`).
 */
export function BulkHeaderCheckbox({ bulk, pageIds }: { bulk: BulkSelection; pageIds: readonly string[] }) {
  if (!bulk.offered) return null;
  return (
    <th scope="col" className={styles.checkCell}>
      <Checkbox
        label={BULK.selectPage}
        hideLabel
        checked={pageCheckState(bulk.selected, pageIds)}
        onCheckedChange={(next) => bulk.setPage(pageIds, next)}
      />
    </th>
  );
}
