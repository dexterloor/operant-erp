'use client';

import { BULK } from '@/copy/bulk';
import { Checkbox } from '@/components/ui/Checkbox';
import type { BulkSelection } from './useBulkSelection';
import styles from './Bulk.module.css';

/**
 * One row's checkbox cell (round-6 §2c, 24×24 hit area through `Checkbox`). `firstColumn` is the row's name, code or
 * number as shown in its first column (R4-1: "Select {first column}"). Renders nothing unless bulk is offered.
 */
export function BulkRowCheckbox({ bulk, id, firstColumn }: { bulk: BulkSelection; id: string; firstColumn: string }) {
  if (!bulk.offered) return null;
  return (
    <td className={styles.checkCell}>
      <Checkbox label={BULK.selectRow(firstColumn)} hideLabel checked={bulk.isSelected(id)} onCheckedChange={(next) => bulk.toggle(id, next)} />
    </td>
  );
}
