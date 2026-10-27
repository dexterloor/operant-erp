'use client';

import { ChangePasswordForm } from '@/components/auth/ChangePasswordForm';
import { assignLocation } from '@/lib/auth/results';
import { unwiredChangePassword } from '@/lib/auth/unwired';

/** B-3b passes the BFF-backed action here; a 401 goes to sign-in, which shows the "session ended" notice. */
export function ChangePasswordRoute() {
  return <ChangePasswordForm changePassword={unwiredChangePassword} onSessionEnded={() => assignLocation('/login')} />;
}
