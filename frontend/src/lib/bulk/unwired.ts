import type { BulkRunAction } from './results';

// Props only (ruling item 9; RED M-11): until B-3b wires bulk runs to the BFF, a run answers "unreachable" — nothing is
// sent anywhere, which is also what the bar then says. The one stub source for B-3b; imported by nothing until B-3b
// (RED M-11).
export const unwiredBulkRun: BulkRunAction = async () => ({ kind: 'unreachable' });
