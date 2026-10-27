import type { ChangePasswordAction, RedeemResetAction, RequestResetAction } from './results';

// B-3a2 builds the pages, not the wiring (ruling item 9: props only — no fetch, no BFF route handler, no cookie code,
// no API client). Until B-3b replaces these with calls to the BFF, every action answers "unreachable": nothing is sent
// anywhere, which is also what the page then says. The token argument is ignored and never stored.
export const unwiredRequestReset: RequestResetAction = async () => ({ kind: 'unreachable' });
export const unwiredRedeemReset: RedeemResetAction = async () => ({ kind: 'unreachable' });
export const unwiredChangePassword: ChangePasswordAction = async () => ({ kind: 'unreachable' });
