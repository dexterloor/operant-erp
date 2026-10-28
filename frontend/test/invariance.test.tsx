import { cleanup, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import type { BulkRow } from '@/components/preview/fixtures/bulk';
import type { Permission } from '@/lib/api/permissions';
import type { Role } from '@/lib/api/roles';
import { MODULE_HEADINGS, PERMISSION_LABELS } from '@/copy/permissionLabels';
import { CUSTOM_ROLE, PERMISSIONS } from '@/components/preview/fixtures/permissions';
import { BulkHarness } from './bulk/harness';
import { ChecklistHarness } from './permissions/harness';


// Invariance tests (RED H-5 items 1–2). B-4 step 3 adds the CAP half (item 2), which replaces any literal-50 grep: the
// bar's cap is whatever `maxMembers` says, so a client that typed a cap anywhere makes the two runs disagree. B-4 step 4
// adds the name/code half (item 1) for the permission checklist; the picker's arrives with step 5.

const rows = (n: number): BulkRow[] =>
  Array.from({ length: n }, (_, i) => ({ id: `30000000-0000-4000-8000-${String(i + 1).padStart(12, '0')}`, name: `Row ${i + 1}`, code: `R${i + 1}` }));

async function selectAndRead(maxMembers: number, select: number) {
  render(<BulkHarness run={async () => ({ kind: 'unreachable' })} maxMembers={maxMembers} pages={[rows(8)]} />);
  const user = userEvent.setup();
  for (let i = 1; i <= select; i += 1) await user.click(screen.getByRole('checkbox', { name: `Select Row ${i}` }));
  const del = within(screen.getByRole('region', { name: 'Actions for the selected rows' })).getByRole('button', { name: 'Delete' });
  return { del, user };
}

describe('invariance — the bulk cap is the prop, nothing else (RED H-5 item 2)', () => {
  it('maxMembers 3: selecting 4 makes Delete aria-disabled with the reason built from 3', async () => {
    const { del, user } = await selectAndRead(3, 4);
    expect(del).toHaveAttribute('aria-disabled', 'true');
    expect(del).toHaveAccessibleDescription('You can delete up to 3 at a time.');
    await user.click(del);
    expect(screen.queryByRole('dialog')).toBeNull();
  });

  it('maxMembers 3: selecting 3 is allowed', async () => {
    const { del } = await selectAndRead(3, 3);
    expect(del).not.toHaveAttribute('aria-disabled');
  });

  it('maxMembers 7: selecting 7 is allowed, and Delete opens its dialog', async () => {
    const { del, user } = await selectAndRead(7, 7);
    expect(del).not.toHaveAttribute('aria-disabled');
    await user.click(del);
    expect(screen.getByRole('dialog')).toHaveAccessibleName('Delete 7 suppliers?');
  });

  it('maxMembers 7: selecting 8 is refused with the reason built from 7', async () => {
    const { del } = await selectAndRead(7, 8);
    expect(del).toHaveAccessibleDescription('You can delete up to 7 at a time.');
  });
});

// ---------------------------------------------------------------------------------------------------------------------
// RED H-5 item 1 — PermissionChecklist name/code invariance. Render from the fixture, then from the same fixture with
// every permission code replaced through a consistent random map (labels then fall back to the served `description`),
// `sod_rows` kept. Over a tick-all script, the structure must be identical: the same boxes aria-disabled, the same
// number of reason lines per box, the same "Left out" set per group, the same group states, the same ticks. Any client
// behaviour keyed on a code (a literal, a prefix, a label lookup used as a rule) makes the two runs differ. Boxes are
// matched across runs by the code they stand for (accessible name → code in each run), never by position, because the
// display order inside a group is the label table's (copy) for known codes and served order for unknown ones.

const randomCode = (used: Set<string>): string => {
  const word = () => Array.from({ length: 6 }, () => String.fromCharCode(97 + Math.floor(Math.random() * 26))).join('');
  for (;;) {
    const c = `${word()}.${word()}`;
    if (!used.has(c) && !(c in PERMISSION_LABELS)) {
      used.add(c);
      return c;
    }
  }
};

interface Run {
  role: Role;
  permissions: Permission[];
  /** Accessible name of a box in this run → the ORIGINAL code it stands for. */
  nameToCode: Map<string, string>;
}

function originalRun(): Run {
  return { role: CUSTOM_ROLE, permissions: PERMISSIONS, nameToCode: new Map(PERMISSIONS.map((p) => [PERMISSION_LABELS[p.code]!.label, p.code])) };
}

function renamedRun(): Run {
  const used = new Set<string>();
  const map = new Map(PERMISSIONS.map((p) => [p.code, randomCode(used)]));
  return {
    role: { ...CUSTOM_ROLE, permission_codes: CUSTOM_ROLE.permission_codes.map((c) => map.get(c)!) },
    permissions: PERMISSIONS.map((p) => ({ ...p, code: map.get(p.code)!, sod_rows: p.sod_rows.map((r) => ({ ...r })) })),
    nameToCode: new Map(PERMISSIONS.map((p) => [p.description, p.code])),
  };
}

/** Read straight from the DOM (getByRole over ~75 boxes per step is too slow for a 15-step script). */
function snapshot(run: Run) {
  const nameOf = (input: Element) => input.closest('label')?.textContent ?? '';
  const groups = [...document.querySelectorAll('section[aria-labelledby]')].map((region) => {
    const heading = document.getElementById(region.getAttribute('aria-labelledby')!)?.textContent ?? '';
    const groupBox = region.querySelector<HTMLInputElement>('input[type="checkbox"]')!;
    const line = region.querySelector('[role="status"]')?.textContent ?? '';
    const leftOut = [...run.nameToCode.entries()].filter(([name]) => line.includes(name)).map(([, code]) => code).sort();
    return { heading, group: nameOf(groupBox), state: groupBox.indeterminate ? 'mixed' : groupBox.checked, leftOut, lineEmpty: line === '' };
  });
  const boxes: Record<string, { checked: boolean; disabled: boolean; reasons: number }> = {};
  for (const input of document.querySelectorAll<HTMLInputElement>('input[type="checkbox"]')) {
    const code = run.nameToCode.get(nameOf(input));
    if (code === undefined) continue; // a group box
    const ids = (input.getAttribute('aria-describedby') ?? '').split(' ').filter(Boolean);
    const reasons = ids.filter((id) => /^Can't be in the same role as /.test(document.getElementById(id)?.textContent ?? '')).length;
    boxes[code] = { checked: input.checked, disabled: input.getAttribute('aria-disabled') === 'true', reasons };
  }
  expect(Object.keys(boxes)).toHaveLength(PERMISSIONS.length);
  expect(groups).toHaveLength(MODULE_HEADINGS.length);
  return { groups, boxes };
}

async function tickAllScript(run: Run) {
  render(<ChecklistHarness role={run.role} permissions={run.permissions} />);
  const user = userEvent.setup();
  const out = [snapshot(run)];
  const headings = MODULE_HEADINGS.map((m) => m.heading);
  for (const heading of [...headings, 'Purchasing', 'Sales', 'Purchasing']) {
    const label = [...document.querySelectorAll('label')].find((l) => l.textContent === `All ${heading} permissions`)!;
    await user.click(label.querySelector('input')!);
    out.push(snapshot(run));
  }
  cleanup();
  return out;
}

describe('invariance — the checklist decides nothing from a permission code (RED H-5 item 1)', () => {
  it('every code renamed: the same structure at every step of a tick-all script', { timeout: 60_000 }, async () => {
    const a = await tickAllScript(originalRun());
    const b = await tickAllScript(renamedRun());
    expect(b).toEqual(a);
    // Non-vacuous: the script reaches refused boxes, reason lines, a Left-out set and a mixed group.
    expect(a[0]!.groups.some((g) => g.state === 'mixed')).toBe(true);
    expect(Object.values(a[0]!.boxes).some((x) => x.disabled && x.reasons > 0)).toBe(true);
    expect(a.some((s) => s.groups.some((g) => g.leftOut.length > 0))).toBe(true);
  });
});
