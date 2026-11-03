'use client';

import Link from 'next/link';
import { ResetPasswordForm } from '@/components/auth/ResetPasswordForm';
import { wiredRedeemReset } from '@/lib/auth/wired';

/** The BFF-backed redemption (B-3b C8): the token is passed only as the action's argument. */
export function ResetPasswordRoute() {
  return <ResetPasswordForm redeem={wiredRedeemReset} linkComponent={Link} />;
}
