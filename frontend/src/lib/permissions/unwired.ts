import type { SaveRoleAction } from './results';

// Props only (ruling item 9; RED M-11): until B-3b wires it to the BFF, saving answers "unreachable" — nothing is sent
// anywhere, which is also what the checklist then says. The one stub source for B-3b; imported by nothing until B-3b
// (RED M-11).
export const unwiredSaveRole: SaveRoleAction = async () => ({ kind: 'unreachable' });
