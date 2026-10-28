import { describe, expect, it } from 'vitest';
import { initialSelection, isOverCap, keyIn, pageCheckState, reduceSelection, selectedIn, type SelectionState } from '@/lib/bulk/selection';

// Pure bulk selection (lib/bulk/selection.ts): scope-keyed state with a DERIVED reset (RED M-8) and the selection's
// idempotency key (Revision 1 C-1).

const A = 'live|0';
const B = 'live|1';

const run = (actions: Parameters<typeof reduceSelection>[1][], from: SelectionState = initialSelection(A)) => actions.reduce(reduceSelection, from);

describe('lib/bulk/selection', () => {
  it('toggles ids in selection order; a no-op toggle returns the same state', () => {
    const s = run([
      { type: 'toggle', scopeKey: A, id: 'b', next: true },
      { type: 'toggle', scopeKey: A, id: 'a', next: true },
    ]);
    expect(selectedIn(s, A)).toEqual(['b', 'a']);
    expect(reduceSelection(s, { type: 'toggle', scopeKey: A, id: 'a', next: true })).toBe(s);
    expect(selectedIn(reduceSelection(s, { type: 'toggle', scopeKey: A, id: 'b', next: false }), A)).toEqual(['a']);
  });

  it('another scope reads as empty without any write (derived reset), and its key is not visible', () => {
    const s = run([
      { type: 'toggle', scopeKey: A, id: 'a', next: true },
      { type: 'keyed', scopeKey: A, key: 'k1' },
    ]);
    expect(selectedIn(s, B)).toEqual([]);
    expect(keyIn(s, B)).toBeNull();
    expect(selectedIn(s, A)).toEqual(['a']);
    expect(keyIn(s, A)).toBe('k1');
  });

  it('the first change in a new scope starts from empty, never from the old scope ids', () => {
    const s = run([
      { type: 'toggle', scopeKey: A, id: 'a', next: true },
      { type: 'page', scopeKey: B, pageIds: ['x', 'y'], next: true },
    ]);
    expect(s.scopeKey).toBe(B);
    expect(selectedIn(s, B)).toEqual(['x', 'y']);
  });

  it('the page action selects and unselects exactly the page ids', () => {
    const s = run([{ type: 'toggle', scopeKey: A, id: 'b', next: true }, { type: 'page', scopeKey: A, pageIds: ['a', 'b', 'c'], next: true }]);
    expect(selectedIn(s, A)).toEqual(['b', 'a', 'c']);
    expect(selectedIn(reduceSelection(s, { type: 'page', scopeKey: A, pageIds: ['a', 'b', 'c'], next: false }), A)).toEqual([]);
  });

  it('a key is kept while the selection is unchanged and dropped by any change, by clear and by a stale-scope key write', () => {
    const keyed = run([
      { type: 'toggle', scopeKey: A, id: 'a', next: true },
      { type: 'keyed', scopeKey: A, key: 'k1' },
    ]);
    expect(keyIn(reduceSelection(keyed, { type: 'toggle', scopeKey: A, id: 'a', next: true }), A)).toBe('k1');
    expect(keyIn(reduceSelection(keyed, { type: 'toggle', scopeKey: A, id: 'b', next: true }), A)).toBeNull();
    expect(keyIn(reduceSelection(keyed, { type: 'toggle', scopeKey: A, id: 'a', next: false }), A)).toBeNull();
    expect(keyIn(reduceSelection(keyed, { type: 'page', scopeKey: A, pageIds: ['a', 'c'], next: true }), A)).toBeNull();
    expect(keyIn(reduceSelection(keyed, { type: 'clear' }), A)).toBeNull();
    expect(reduceSelection(keyed, { type: 'keyed', scopeKey: B, key: 'k9' })).toBe(keyed);
  });

  it('pageCheckState: clear, mixed, ticked — over the page ids only', () => {
    expect(pageCheckState([], ['a', 'b'])).toBe(false);
    expect(pageCheckState(['a'], ['a', 'b'])).toBe('mixed');
    expect(pageCheckState(['a', 'b'], ['a', 'b'])).toBe(true);
    expect(pageCheckState(['z'], ['a', 'b'])).toBe(false);
    expect(pageCheckState([], [])).toBe(false);
  });

  it('isOverCap compares with the given cap, inclusive of the cap itself', () => {
    expect(isOverCap(3, 3)).toBe(false);
    expect(isOverCap(4, 3)).toBe(true);
    expect(isOverCap(7, 7)).toBe(false);
  });
});
