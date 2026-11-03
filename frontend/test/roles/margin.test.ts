import { describe, expect, it } from 'vitest';
import { OFFERED_JONAS_BY_ADMIN, ROLE_ID } from '@/components/preview/fixtures/roles';
import { afterSetOf, type Intents } from '@/lib/roles/intents';
import { marginKey, marginWarningOf } from '@/lib/roles/margin';
import { previewOf } from '@/lib/roles/effectivePreview';
import { ROLES } from '@/components/preview/fixtures/roles';

const ids = (...n: string[]) => new Set(n.map((x) => ROLE_ID[x]!));
const m = (entries: Array<[string, 'give' | 'take-away']>): Intents => new Map(entries.map(([n, i]) => [ROLE_ID[n]!, i]));

describe('margin warning (R4-2 + 4b-2), served data only', () => {
  it('F-7 step 2: a staged proposal of finance with held buyer → pair form naming finance', () => {
    const i = m([['finance', 'give']]);
    expect(marginWarningOf(false, OFFERED_JONAS_BY_ADMIN, i, afterSetOf(ids('buyer'), i))).toEqual({ form: 'pair', roleNames: ['finance'] });
  });

  it('nothing staged → none; held served exposure → held form, which wins', () => {
    expect(marginWarningOf(false, OFFERED_JONAS_BY_ADMIN, new Map(), ids('buyer')).form).toBe('none');
    const i = m([['finance', 'give']]);
    expect(marginWarningOf(true, OFFERED_JONAS_BY_ADMIN, i, afterSetOf(ids('buyer'), i))).toEqual({ form: 'held' });
  });

  it('the partner must be in the after-save set: staging buyer\'s take-away with finance removes the pair', () => {
    const i = m([['finance', 'give'], ['buyer', 'take-away']]);
    expect(marginWarningOf(false, OFFERED_JONAS_BY_ADMIN, i, afterSetOf(ids('buyer'), i)).form).toBe('none');
  });

  it('marginKey changes only when the form or its roles change', () => {
    expect(marginKey({ form: 'pair', roleNames: ['a'] })).not.toBe(marginKey({ form: 'pair', roleNames: ['b'] }));
    expect(marginKey({ form: 'none' })).toBe('none');
  });
});

describe('effective preview — proposals never change it', () => {
  it('a staged proposal adds nothing; a staged direct give adds; a take-away takes away', () => {
    expect(previewOf(ROLES, ids('buyer'), m([['finance', 'give']]), OFFERED_JONAS_BY_ADMIN).added.size).toBe(0);
    const p = previewOf(ROLES, ids('buyer'), m([['viewer', 'give']]), OFFERED_JONAS_BY_ADMIN);
    expect(p.added.has('sales_order.view')).toBe(true);
    const t = previewOf(ROLES, ids('buyer'), m([['buyer', 'take-away']]), OFFERED_JONAS_BY_ADMIN);
    expect(t.after.size).toBe(0);
    expect(t.takenAway.size).toBe(ROLES.find((r) => r.name === 'buyer')!.permission_codes.length);
  });
});
