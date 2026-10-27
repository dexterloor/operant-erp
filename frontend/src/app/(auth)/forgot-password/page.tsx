import type { Metadata } from 'next';
import { REQUEST_RESET } from '@/copy/password';
import { ForgotPasswordRoute } from './ForgotPasswordRoute';

// Rendered per request so Next sends `Cache-Control: no-store` (a prerendered page would be cached); headers in
// next.config.ts add `Referrer-Policy: no-referrer` for this route.
export const dynamic = 'force-dynamic';

export const metadata: Metadata = { title: REQUEST_RESET.title, referrer: 'no-referrer' };

export default function Page() {
  return <ForgotPasswordRoute />;
}
