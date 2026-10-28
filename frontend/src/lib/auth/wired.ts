import type { RedeemResetAction, RequestResetAction } from './results';
import { redeemReset, requestReset } from '../bff/authCalls';

// The credential pages' actions, wired to the web app's server (b3b-design.md §9.6), replacing two of the B-3a2 stubs.
// Change password stays unwired until BK-1 ships (notice N-1): its success copy says "signed out everywhere", which is
// not true until the API makes it so.
export const wiredRequestReset: RequestResetAction = requestReset;
export const wiredRedeemReset: RedeemResetAction = redeemReset;
