import type { Metadata } from 'next';
import { Suspense } from 'react';
import { SIGN_IN } from '@/copy/shell';
import { LoginRoute } from './LoginRoute';

// Rendered per request; never redirects away because a cookie exists (bff-auth-note.md R1-§6.1).
export const dynamic = 'force-dynamic';

export const metadata: Metadata = { title: SIGN_IN.title };

export default function Page() {
  return (
    <Suspense>
      <LoginRoute />
    </Suspense>
  );
}
