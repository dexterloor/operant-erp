import type { Metadata } from 'next';
import { SET_PASSWORD } from '@/copy/password';
import { ResetPasswordRoute } from './ResetPasswordRoute';

// ux-copy-frontend.md §1.4 / bff-auth-note.md §8: `Cache-Control: no-store` (per-request rendering) and
// `Referrer-Policy: no-referrer` (next.config.ts headers, plus the meta tag below). No analytics, no error reporter and
// no third-party script on this route: the page loads only the app's own `/_next/` chunks.
export const dynamic = 'force-dynamic';

export const metadata: Metadata = { title: SET_PASSWORD.title, referrer: 'no-referrer' };

export default function Page() {
  return <ResetPasswordRoute />;
}
