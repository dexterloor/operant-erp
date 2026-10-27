'use client';

import Link from 'next/link';
import { ForgotPasswordForm } from '@/components/auth/ForgotPasswordForm';
import { unwiredRequestReset } from '@/lib/auth/unwired';

/** B-3b passes the BFF-backed action and the device's remembered workspace (IA-O-2) here. */
export function ForgotPasswordRoute() {
  return <ForgotPasswordForm requestReset={unwiredRequestReset} linkComponent={Link} />;
}
