'use client';

import { ChangePasswordForm } from '@/components/auth/ChangePasswordForm';
import { assignLocation, type DocumentNavigate } from '@/lib/auth/results';
import { wiredChangePassword } from '@/lib/auth/wired';
import { useSessionId } from '../../BffProvider';

export interface ChangePasswordRouteProps {
  /** Injected in tests; a full document load otherwise. */
  navigate?: DocumentNavigate;
  /** Injected in tests; the browser's reload otherwise. */
  reload?: () => void;
}

/**
 * Change password as the person this frame was rendered for (Revision 6 A-12, A-14): the call carries the frame's sid.
 * A 401 → sign-in with the "session ended" notice; a 409 (someone else signed in in this browser) → one reload, which
 * shows whoever is signed in now (c10-design.md FINAL B.3, departure D-5).
 */
export function ChangePasswordRoute({ navigate = assignLocation, reload = () => window.location.reload() }: ChangePasswordRouteProps) {
  const changePassword = wiredChangePassword(useSessionId());
  return <ChangePasswordForm changePassword={changePassword} onSessionEnded={() => navigate('/login?reason=session_ended')} onSessionChanged={reload} />;
}
