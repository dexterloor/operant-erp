import type { RolePickerActions, RoleProposalActions } from './results';

// Props only (ruling item 9; RED M-11): until B-3b wires these to the BFF, every action answers "unreachable" — nothing
// is sent anywhere. The one stub source for B-3b; imported by nothing until B-3b (RED M-11).
export const unwiredRolePickerActions: RolePickerActions = {
  assign: async () => ({ kind: 'unreachable' }),
  propose: async () => ({ kind: 'unreachable' }),
  revoke: async () => ({ kind: 'unreachable' }),
  reread: async () => ({ kind: 'failed', failure: 'unreachable' }),
};

export const unwiredRoleProposalActions: RoleProposalActions = {
  confirm: async () => ({ kind: 'unreachable' }),
  reject: async () => ({ kind: 'unreachable' }),
  withdraw: async () => ({ kind: 'unreachable' }),
  reread: async () => ({ kind: 'failed', failure: 'unreachable' }),
  onPage: () => {},
};
