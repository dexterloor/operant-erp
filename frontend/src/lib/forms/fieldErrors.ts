// The in-app forms' two pure helpers (b4-architecture-r7-proposal.md A-19 item 4). Framework-free: nothing here imports
// react, next, a component or copy (architecture.test.ts).
//
// Deliberately different from the credential pages: `lib/bff/authCalls.ts` turns a 422 whose pointers name none of its
// fields into `server-error`, because a signed-out page must not echo an unexpected server sentence. An in-app form's
// sentences are written for that form, so here a violation that names no field is shown at form level instead. Both
// rules stand; `authCalls.ts` is not changed by this module.

import type { FieldErrors, Violation } from '../results';

/**
 * A 422's violations onto a form's fields. A pointer `/<name>` with `<name>` in `fields` goes to that field, and the
 * first message for a field wins (a later one for the same field is not shown, as on the credential pages). Anything
 * else — an empty pointer, a query parameter (`?…`), an unknown or nested field — goes to `formErrors`, verbatim and in
 * order, so a 422 that maps nothing still shows its sentences.
 */
export function fieldErrorsFrom<F extends string>(violations: readonly Violation[], fields: readonly F[]): { fieldErrors: FieldErrors<F>; formErrors: string[] } {
  const fieldErrors: FieldErrors<F> = {};
  const formErrors: string[] = [];
  for (const v of violations) {
    const name = v.pointer.startsWith('/') ? v.pointer.slice(1) : null;
    if (name !== null && (fields as readonly string[]).includes(name)) {
      const f = name as F;
      if (fieldErrors[f] === undefined) fieldErrors[f] = v.message;
    } else {
      formErrors.push(v.message);
    }
  }
  return { fieldErrors, formErrors };
}

/**
 * The PATCH body: only the fields whose value differs from the loaded one, compared EXACTLY (no trim, no case folding —
 * the writers compare exact strings, and a body holding only real changes keeps the audit diff to what the person
 * typed). Typed against the operation's input:
 * `changedFields<UpdateWarehouseInput, 'code' | 'name'>(['code', 'name'], initial, current)`, so a key the input does not
 * declare is a type error and the mirrored input (C-1) keeps the sent keys honest. `I` cannot be inferred (`NoInfer`),
 * so a call without the operation's input type is a type error; only the listed `keys` are ever read, so a wider
 * form-state object cannot leak a key into the body.
 *
 * Blind spot, stated: C-2's body-key check (`test/contract/clientOps.test.ts`) reads object-literal request bodies only,
 * so it does not see a body built here. The typing above is what keeps these keys honest, not that check.
 */
export function changedFields<I extends object, K extends keyof I = never>(
  keys: readonly K[],
  initial: NoInfer<Pick<I, K>>,
  current: NoInfer<Pick<I, K>>,
): Partial<Pick<I, K>> {
  const out: Partial<Pick<I, K>> = {};
  for (const k of keys) {
    if (current[k] !== initial[k]) out[k] = current[k];
  }
  return out;
}
