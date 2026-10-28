import type { DeletePersonAction, RestorePersonAction } from './results';

// Props only (ruling item 9; RED M-11): until B-3b wires these to the BFF, both actions answer "unreachable" — nothing
// is sent anywhere, which is also what the dialog then says. The one stub source for B-3b; imported by nothing until
// B-3b (RED M-11).
export const unwiredDeletePerson: DeletePersonAction = async () => ({ kind: 'unreachable' });
export const unwiredRestorePerson: RestorePersonAction = async () => ({ kind: 'unreachable' });
