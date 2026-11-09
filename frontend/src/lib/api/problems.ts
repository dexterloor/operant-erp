// Platform-wide refusal rules the screens branch on (Revision 7 A-18 "Refusal codes", S-1/S-2). Imports nothing.
// `transaction.contention` (`backend/src/Platform/Http/Problem/TransactionContention.php`) can come from any operation;
// its sentence tells the reader to try again, so the action that met it stays available (warehouses-ux-copy.md B-7
// "Exception — P1"). It is compared in one place per family (Revision 8 R8-4), each pinned: `lib/warehouses/wired.ts`, which
// maps it to `{ kind: 'contention' }`, and `lib/items/wired.ts`, which never turns it into `refused-archived`.
export const PLATFORM_REFUSAL = { contention: 'transaction.contention' } as const;
