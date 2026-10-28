'use client';

import Link from 'next/link';
import { ForgotPasswordForm } from '@/components/auth/ForgotPasswordForm';
import { wiredRequestReset } from '@/lib/auth/wired';

/** The BFF-backed reset request (B-3b C8). The device's remembered workspace (IA-O-2) is not built yet. */
export function ForgotPasswordRoute() {
  return <ForgotPasswordForm requestReset={wiredRequestReset} linkComponent={Link} />;
}
