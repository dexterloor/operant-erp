import type { ChangePasswordAction, RedeemResetAction, RequestResetAction } from './results';
import { changePassword, redeemReset, requestReset } from '../bff/authCalls';

// The credential pages' actions, wired to the web app's server (b3b-design.md §9.6; c10-design.md FINAL B.2).
export const wiredRequestReset: RequestResetAction = requestReset;
export const wiredRedeemReset: RedeemResetAction = redeemReset;
/** Change password as the person the app frame was rendered for: `sid` is that frame's (b4-architecture.md Revision 6 A-12). */
export const wiredChangePassword = (sid: string): ChangePasswordAction => (values) => changePassword(values, sid);
