import type { ChangePasswordAction } from './results';

// The one credential action still unwired: change password waits for BK-1 (notice N-1). It answers "unreachable":
// nothing is sent anywhere, which is also what the page then says. The reset request and redemption are wired
// (lib/auth/wired.ts, B-3b C8).
export const unwiredChangePassword: ChangePasswordAction = async () => ({ kind: 'unreachable' });
