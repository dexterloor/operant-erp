export const FIXTURE_ONLY = '__FIXTURE_ONLY__'; // BUILT_IN_ROLE_NAMES: owner, admin, buyer, purchasing_manager, warehouse_operator, sales_rep, finance, viewer
// ^ Line 1 is load-bearing: the marker string is grepped for in `.next/static` after `next build` (RED L-2: it must
// never ship), and the role-name list is read by test/architecture.test.ts's literal backstop (RED H-5 item 4, L-1),
// copied from `backend/src/Modules/UserManagement/Domain/DefaultRoles.php:57-64`.
//
// THE ONE FIXTURE COPY (RED L-1), for the dev-only component preview and for tests. Props only: no fetch interception,
// no fake endpoint, so not "mock API data". Only facts true of the served API today: the `User` shape
// (`UserResource.php`), refusal sentences quoted from shipped code with file:line. People and emails are neutral
// placeholders (G-37). Only `components/preview` and `test/` may import this (architecture.test.ts).

import type { User } from '@/lib/api/users';
import type { Refused } from '@/lib/results';

export const VIEWER_ID = '00000000-0000-4000-8000-000000000001';

/** The viewer's own page (ST-4f row 11). */
export const PERSON_SELF: User = {
  id: VIEWER_ID,
  email: 'admin@example.com',
  display_name: 'Sample Admin',
  is_active: true,
  archived_at: null,
  roles: [{ id: '10000000-0000-4000-8000-000000000002', name: 'admin' }],
  margin_exposure: false,
};

export const PERSON_ACTIVE: User = {
  id: '00000000-0000-4000-8000-000000000002',
  email: 'person.one@example.com',
  display_name: 'Sample Person',
  is_active: true,
  archived_at: null,
  roles: [{ id: '10000000-0000-4000-8000-000000000006', name: 'sales_rep' }],
  margin_exposure: false,
};

/** Deleted while active: Restore's "will be able to sign in again" body. Delete took every role away (§2.1 E1). */
export const PERSON_DELETED_ACTIVE: User = {
  id: '00000000-0000-4000-8000-000000000003',
  email: 'person.two@example.com',
  display_name: 'Sample Deleted',
  is_active: true,
  archived_at: '2026-10-22T06:05:00Z',
  roles: [],
  margin_exposure: false,
};

/** Deleted while switched off: Restore's "will come back switched off" body (§2.1 E8). */
export const PERSON_DELETED_SWITCHED_OFF: User = {
  id: '00000000-0000-4000-8000-000000000004',
  email: 'person.three@example.com',
  display_name: 'Sample Switched Off',
  is_active: false,
  archived_at: '2026-10-09T01:30:00Z',
  roles: [],
  margin_exposure: false,
};

const refused = (rule: string, message: string): Refused => ({ kind: 'refused', rule, message });

/** Delete refusals, as served. Bodies are the server's sentences; the client keeps no copy of them. */
export const DELETE_REFUSALS: Refused[] = [
  // UserLiveness.php:63, thrown at :124
  refused('app_user.marker_needs_owner', 'Only an owner can do this, because this person holds a role that makes them an owner.'),
  // UserLiveness.php:60, thrown at :110 (reached only by a stale page; BE item B-1 changes the wording)
  refused('app_user.actor_is_subject', 'Nobody can switch off their own account. Ask someone else who manages people to do it.'),
  // UserLiveness.php:66, thrown at :135
  refused('app_user.last_owner', 'This would leave the workspace with no owner. Make someone else an owner first.'),
  // UserUpdate.php:15, thrown at UserDeletion.php:89
  refused('app_user.is_archived', "This person's account has been deleted, so it can't be changed."),
];

/** Restore refusals, as served. */
export const RESTORE_REFUSALS: Refused[] = [
  // UserRestoration.php:60, returned at :195
  refused('app_user.email_taken', "Someone else in this workspace now uses this person's email address, so the account can't be restored."),
  // UserRestoration.php:57, returned at :190
  refused('app_user.is_not_archived', "This person's account hasn't been deleted, so there is nothing to restore."),
  // UserLiveness.php:63 (reached only when a database edit left a role behind)
  refused('app_user.marker_needs_owner', 'Only an owner can do this, because this person holds a role that makes them an owner.'),
];
