// The list envelope every collection serves (RED H-4: `OfferedRoleCollection.php:70-78`, `PermissionCollection.php:65-73`,
// `RoleGrantProposalCollection.php` at `732b60a`). When BK-5 lands each list's own generated type replaces it.
// Types only: this file imports nothing (architecture.test.ts).

export interface Collection<T> {
  items: T[];
  /** Pass as `after` for the next page (Older); null when there is none. */
  next_cursor: string | null;
  /** Pass as `before` for the previous page (Newer); null when there is none. */
  previous_cursor: string | null;
  tenant_has_rows: boolean;
}

/** The two cursor parameters are mutually exclusive (`after` | `before`), so the pager says which (RED H-4). */
export type PageDirection = 'after' | 'before';
