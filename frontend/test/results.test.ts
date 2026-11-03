import { describe, expectTypeOf, it } from 'vitest';
import type * as Shared from '@/lib/results';
import type * as Auth from '@/lib/auth/results';

// Revision 2 C-3: one copy of each kind; the auth module re-exports it, so B-3a's imports are unchanged.
describe('lib/results (RED M-2, Revision 2 C-3)', () => {
  it('auth re-exports the shared kinds (same types, not copies)', () => {
    expectTypeOf<Auth.Unreachable>().toEqualTypeOf<Shared.Unreachable>();
    expectTypeOf<Auth.ServerError>().toEqualTypeOf<Shared.ServerError>();
    expectTypeOf<Auth.RateLimited>().toEqualTypeOf<Shared.RateLimited>();
  });

  it('Refused carries the served problem fields rule + message (ApiProblem.php getRule / message)', () => {
    expectTypeOf<Shared.Refused>().toEqualTypeOf<{ kind: 'refused'; rule: string; message: string }>();
    expectTypeOf<Shared.SessionEnded['kind']>().toEqualTypeOf<'session-ended'>();
    expectTypeOf<Shared.Forbidden['kind']>().toEqualTypeOf<'forbidden'>();
    expectTypeOf<Shared.NotFound['kind']>().toEqualTypeOf<'not-found'>();
  });
});
