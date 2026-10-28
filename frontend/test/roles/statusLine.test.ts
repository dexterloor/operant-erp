import { describe, expect, it } from 'vitest';
import { PICKER_STATUS } from '@/copy/roles';
import { formatList } from '@/lib/format/lists';
import type { Outcome } from '@/lib/roles/save';
import { saveSummary, tickStatus } from '@/lib/roles/statusLine';

const o = (name: string, kind: Outcome['request']['kind'], result: Outcome['result']): Outcome => ({ request: { roleId: name, roleName: name, kind }, result });
const refused = { kind: 'refused', rule: 'x.y', message: 'No.' } as const;

describe('status line on a tick (4b-1, R4-4 L-1, L-3)', () => {
  it('F-7 step 2: two newly unavailable roles, then the pair warning', () => {
    const s = tickStatus(PICKER_STATUS, formatList, { newlyUnavailable: ['admin', 'sales_rep'], availableAgain: [], cause: 'finance', marginSentences: ['W.'] });
    expect(s).toBe("admin and sales_rep can't be given now, because each conflicts with finance. W.");
  });
  it('one role, and available again', () => {
    expect(tickStatus(PICKER_STATUS, formatList, { newlyUnavailable: ['admin'], availableAgain: [], cause: 'finance', marginSentences: [] })).toBe("admin can't be given now, because it conflicts with finance.");
    expect(tickStatus(PICKER_STATUS, formatList, { newlyUnavailable: [], availableAgain: ['admin', 'sales_rep'], cause: 'finance', marginSentences: [] })).toBe('admin and sales_rep can be given again.');
  });
  it('a tick that changes nothing else says nothing', () => {
    expect(tickStatus(PICKER_STATUS, formatList, { newlyUnavailable: [], availableAgain: [], cause: 'viewer', marginSentences: [] })).toBe('');
  });
});

describe('save summary (R4-1 order; 4b-1; 4b-2; R1-1; R1-2 case B)', () => {
  it('clauses in order, then the reasons sentence when any request was refused', () => {
    const s = saveSummary(PICKER_STATUS, formatList, {
      outcomes: [o('buyer', 'take-away', { kind: 'taken-away' }), o('viewer', 'give', refused), o('admin', 'give', { kind: 'given', role: {} as never }), o('finance', 'propose', { kind: 'proposed', proposal: {} as never })],
      interrupted: false,
      nothingStillMarked: false,
      heldForm: null,
    });
    expect(s).toBe("Given: admin. Proposed, waiting for an owner: finance. Taken away: buyer. Not given: viewer. Each change that wasn't saved has a message with the reason.");
  });
  it('the held form follows the outcome clauses (4b-2)', () => {
    expect(saveSummary(PICKER_STATUS, formatList, { outcomes: [o('admin', 'give', { kind: 'given', role: {} as never })], interrupted: false, nothingStillMarked: false, heldForm: 'H.' })).toBe('Given: admin. H.');
  });
  it('interrupted: 2xx clauses, then "The save didn\'t finish…"; case B: "All your changes are now saved."', () => {
    const outcomes = [o('admin', 'give', { kind: 'given', role: {} as never }), o('viewer', 'give', { kind: 'unreachable' }), o('finance', 'propose', { kind: 'not-sent' })];
    expect(saveSummary(PICKER_STATUS, formatList, { outcomes, interrupted: true, nothingStillMarked: false, heldForm: null })).toBe("Given: admin. The save didn't finish. A message says what wasn't saved.");
    expect(saveSummary(PICKER_STATUS, formatList, { outcomes, interrupted: true, nothingStillMarked: true, heldForm: null })).toBe('Given: admin. All your changes are now saved.');
  });
});
