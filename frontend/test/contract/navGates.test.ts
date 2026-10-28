import { describe, expect, it } from 'vitest';
import { NAVIGATION, type NavGroupDef } from '@/lib/navigation';
import { serverOperation, type SurfaceOperation } from '@/lib/bff/server/surface';

// C-2b (b3b §14, strict form per final RED L-2): every sidebar item's operation exists in the contract and is gated by
// exactly one code, equal to the item's permission. An operation that is open (`none`), `derived`, multi-code or absent
// fails, naming the item.

function navGateViolations(nav: readonly NavGroupDef[], lookup: (id: string) => SurfaceOperation | undefined): string[] {
  const out: string[] = [];
  for (const g of nav) {
    for (const item of g.items) {
      const op = lookup(item.operation);
      if (!op) {
        out.push(`${item.label}: ${item.operation} is not in the contract`);
        continue;
      }
      const p = op.permission;
      const ok = p.kind === 'all_of' && p.codes.length === 1 && p.codes[0] === item.permission;
      if (!ok) out.push(`${item.label}: ${item.operation} is gated ${JSON.stringify(p)}, not exactly '${item.permission}'`);
    }
  }
  return out;
}

const tryOp = (id: string) => {
  try {
    return serverOperation(id);
  } catch {
    return undefined;
  }
};

describe('C-2b — the sidebar map matches the contract (strict)', () => {
  it('every navigation item is gated by exactly its permission', () => {
    expect(NAVIGATION.flatMap((g) => g.items).length).toBeGreaterThan(10);
    expect(navGateViolations(NAVIGATION, tryOp)).toEqual([]);
  });

  it('planted controls: open, derived, multi-code, absent and mismatched items each go red, naming the item', () => {
    const item = (label: string, operation: string, permission: string) => ({ label, href: '/x', icon: 'box' as const, operation, permission });
    const planted: NavGroupDef[] = [
      {
        label: 'Planted',
        items: [
          item('Open', 'GetCurrentUser', 'app_user.view'),
          item('Derived', 'CreateExport', 'app_user.view'),
          item('Many', 'ListReorderSuggestions', 'forecast.view'),
          item('Absent', 'ListNothing', 'nothing.view'),
          item('Mismatch', 'ListUsers', 'role.view'),
        ],
      },
    ];
    const got = navGateViolations(planted, tryOp).map((v) => v.split(':')[0]);
    expect(got).toEqual(['Open', 'Derived', 'Many', 'Absent', 'Mismatch']);
  });

  it('an unknown operation is an error from the reader, not undefined', () => {
    expect(() => serverOperation('ListNothing')).toThrow(/ListNothing/);
  });
});
