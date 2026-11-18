// GR-2 H-1 (gr-2-h1-spar.md "Proposed converged text"; first RED gr-2-h1-red.md M-1): the one Idempotency-Key a page's
// stock- or money-moving create holds across an unknown outcome. A key is minted on the first `key()` and stored BEFORE the
// caller sends it (write-ahead: a send that throws leaves it held); every later `key()` returns the same one until
// `consumed()`, which the caller calls only once the key is known used up — after a well-formed 201, or after a 409
// `idempotency-key-conflict` (an earlier attempt with this key was recorded). Everything else keeps it: an unknown outcome
// may have committed, and a refusal commits nothing, so the next press either replays, conflicts, or runs once. The body is
// never compared; the server decides. One holder per page instance, never stored across page loads (a reload, another tab,
// or a new page mints a new key). Precedent: the bulk surface's key per selection (`src/lib/bulk/results.ts:33-37`).
// `test/architecture.test.ts` requires it for every operation in `STOCK_OR_MONEY_OPERATIONS` (frontend-residuals FE-R12).

export interface HeldKey {
  /** The held key; mints and stores one first if none is held. */
  key(): string;
  /** The held key is used up (a recorded 201, or an idempotency-key conflict): the next `key()` mints a new one. */
  consumed(): void;
}

export function heldKey(newKey: () => string): HeldKey {
  let held: string | null = null;
  return {
    key() {
      held ??= newKey();
      return held;
    },
    consumed() {
      held = null;
    },
  };
}
