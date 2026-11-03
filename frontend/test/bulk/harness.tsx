import { useRef, useState } from 'react';
import { BulkBar, type BulkMode } from '@/components/bulk/BulkBar';
import { BulkHeaderCheckbox } from '@/components/bulk/BulkHeaderCheckbox';
import { BulkNotices, BulkResultMessage } from '@/components/bulk/BulkResultMessage';
import { BulkRowCheckbox } from '@/components/bulk/BulkRowCheckbox';
import { useBulkSelection } from '@/components/bulk/useBulkSelection';
import { TooltipProvider } from '@/components/ui/Tooltip';
import type { BulkRunAction } from '@/lib/bulk/results';
import { SUPPLIER_ARCHIVED, SUPPLIER_NOUNS, SUPPLIER_PAGES, type BulkRow } from '@/components/preview/fixtures/bulk';

// A minimal list for the bulk tests: it is the surface's PARENT (calls useBulkSelection, renders the column, the bar,
// the banner zone and the always-mounted after-message), as a B-3b route will be. Not a test file (vitest include is
// `*.test.*`). Scope buttons stand in for Newer/Older and the Archived view.

export interface HarnessProps {
  run: BulkRunAction;
  maxMembers: number;
  offered?: boolean;
  onReread?: () => void;
  mintKey?: () => string;
  /** Called during every render with the scope being rendered and the selection count it shows (RED M-8 probe). */
  onRender?: (scopeKey: string, count: number) => void;
  pages?: BulkRow[][];
  archived?: BulkRow[];
}

export function BulkHarness({ run, maxMembers, offered = true, onReread = () => undefined, mintKey, onRender, pages = SUPPLIER_PAGES, archived = SUPPLIER_ARCHIVED }: HarnessProps) {
  const h1 = useRef<HTMLHeadingElement>(null);
  const [view, setView] = useState<{ archived: boolean; page: number }>({ archived: false, page: 0 });
  const scopeKey = `${view.archived ? 'archived' : 'live'}|${view.page}`;
  const bulk = useBulkSelection({ scopeKey, offered, mintKey });
  onRender?.(scopeKey, bulk.count);
  const rows = view.archived ? archived : (pages[view.page] ?? []);
  const mode: BulkMode = view.archived ? 'restore' : 'delete';
  return (
    <TooltipProvider delayDuration={0}>
      <main>
        <h1 ref={h1} tabIndex={-1}>
          Suppliers
        </h1>
        <button type="button" onClick={() => setView({ archived: false, page: 0 })}>
          Scope: live page 1
        </button>
        <button type="button" onClick={() => setView({ archived: false, page: 1 })}>
          Scope: live page 2
        </button>
        <button type="button" onClick={() => setView({ archived: true, page: 0 })}>
          Scope: archived
        </button>
        <BulkNotices bulk={bulk} />
        <BulkBar bulk={bulk} mode={mode} nouns={SUPPLIER_NOUNS} maxMembers={maxMembers} run={run} onReread={onReread} fallbackFocusRef={h1} />
        <table>
          <thead>
            <tr>
              <BulkHeaderCheckbox bulk={bulk} pageIds={rows.map((r) => r.id)} />
              <th scope="col">Name</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.id}>
                <BulkRowCheckbox bulk={bulk} id={r.id} firstColumn={r.name} />
                <td>{r.name}</td>
              </tr>
            ))}
          </tbody>
        </table>
        <BulkResultMessage bulk={bulk} fallbackFocusRef={h1} />
      </main>
    </TooltipProvider>
  );
}

/** Deterministic keys: key-1, key-2, … */
export function counterMint() {
  let n = 0;
  return () => `key-${++n}`;
}
