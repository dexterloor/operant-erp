import { describe, expectTypeOf, it } from 'vitest';
import type * as Shared from '@/lib/results';
import type * as Auth from '@/lib/auth/results';

// Revision 2 C-3: one copy of each kind; the auth module re-exports it, so B-3a's imports are unchanged.
describe('lib/results (RED M-2, Revision 2 C-3)', () => {
  it('auth re-exports the shared kinds (same types, not copies)', () => {
    expectTypeOf<Auth.Unreachable>().toEqualTypeOf<Shared.Unreachable>();
    expectTypeOf<Auth.ServerError>().toEqualTypeOf<Shared.ServerError>();
    expectTypeOf<Auth.RateLimited>().toEqualTypeOf<Shared.RateLimited>();
    // Revision 7 A-19 item 4: FieldErrors moved here; the auth module re-exports it (type only).
    expectTypeOf<Auth.FieldErrors<'email'>>().toEqualTypeOf<Shared.FieldErrors<'email'>>();
  });

  it('Refused carries the served problem fields rule + message (ApiProblem.php getRule / message)', () => {
    // Revision 7 A-19 item 3: `violations` is optional and set only by a 422 (projected to exactly three strings).
    expectTypeOf<Shared.Refused>().toEqualTypeOf<{ kind: 'refused'; rule: string; message: string; violations?: readonly Shared.Violation[] }>();
    expectTypeOf<Shared.Violation>().toEqualTypeOf<{ readonly pointer: string; readonly code: string; readonly message: string }>();
    expectTypeOf<Shared.SessionEnded['kind']>().toEqualTypeOf<'session-ended'>();
    expectTypeOf<Shared.Forbidden['kind']>().toEqualTypeOf<'forbidden'>();
    expectTypeOf<Shared.NotFound['kind']>().toEqualTypeOf<'not-found'>();
  });
});
