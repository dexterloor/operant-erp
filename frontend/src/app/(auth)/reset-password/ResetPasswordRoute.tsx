'use client';

import Link from 'next/link';
import { ResetPasswordForm } from '@/components/auth/ResetPasswordForm';
import { unwiredRedeemReset } from '@/lib/auth/unwired';

/** B-3b passes the BFF-backed redemption action here. */
export function ResetPasswordRoute() {
  return <ResetPasswordForm redeem={unwiredRedeemReset} linkComponent={Link} />;
}
