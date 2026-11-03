// Separation-of-duty copy, ONE shared copy keyed by the served SoD id (b4-architecture.md tree: `copy/sod.ts`;
// permission-labels.md rule 4: `ListPermissions` serves `sod_rows[{id, half}]` and no clause text). The picker (step 5)
// takes its clauses from here too.
//
// The clauses are word for word `backend/src/Modules/UserManagement/Application/RoleAssignment.php` `SOD_CLAUSES`
// (UX's clause per row: roles-s2-ux-copy.md §3.10, SoD-3 reworded at A2.1b, SoD-5 signed in roles-s4-ux-copy.md §7 F-c).
// They name the work, never a code, so this is not a copy of `SeparationOfDuty::ROWS`. The sentence is
// ux-copy-frontend.md Revision 3 item 5 (NEW), which supersedes roles-s4-ux-copy.md §5's checklist row.

export const SOD_CLAUSES: Readonly<Record<string, string>> = {
  'SoD-1': 'raise purchase orders and also approve or cancel them',
  'SoD-2': 'raise or approve purchase orders and also record the goods as received',
  'SoD-3': 'issue invoices and also record their payment, void them or credit them',
  'SoD-4': 'confirm sales orders or issue invoices and also ship the goods',
  'SoD-5': 'record goods as returned and also credit the invoice for them',
};

/** Rev 3 item 5, the checklist form: one line per split the box would complete. */
export const checklistConflict = (label: string, clause: string, sodId: string) =>
  `Can't be in the same role as ${label}: one person could then ${clause} (${sodId}).`;

/** Rev 3 item 5, the picker form (round-6 §2a, NEW): one line per conflict entry the display rule keeps. */
export const pickerConflict = (role: string, clause: string, sodId: string) =>
  `Conflicts with ${role}: one person could then ${clause} (${sodId}).`;

/**
 * b4-step4-gaps.md Revision 2, FINAL, confirmed by ux-confirm-b4.md: a served SoD id with no clause above. Only the
 * WORDING falls back; the conflict still counts (it refuses the role and blocks Save roles exactly as a known id does).
 */
export const pickerConflictUnknown = (role: string, sodId: string) =>
  `Conflicts with ${role}: together they would complete a separation-of-duty split (${sodId}).`;

/**
 * b4-step4-gaps.md Revision 2, FINAL; RED F-3, confirmed by ux-confirm-b4.md: the checklist form for a served SoD id with
 * no clause above (a version-skew guard: a backend row deployed before this copy). Only the WORDING falls back; the box
 * is still refused, keeps its marker, and is left out by a group tick exactly as for a known id.
 */
export const checklistConflictUnknown = (label: string, sodId: string) =>
  `Can't be in the same role as ${label}: together they would complete a separation-of-duty split (${sodId}).`;
