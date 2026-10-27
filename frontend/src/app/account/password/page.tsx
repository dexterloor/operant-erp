import type { Metadata } from 'next';
import { CHANGE_PASSWORD } from '@/copy/password';
import { ChangePasswordRoute } from './ChangePasswordRoute';

export const dynamic = 'force-dynamic';

export const metadata: Metadata = { title: CHANGE_PASSWORD.title };

/** PROPOSED route (ia-proposed-edits.md E-2). B-3b puts it inside the app frame once a session can be read. */
export default function Page() {
  return (
    <main>
      <ChangePasswordRoute />
    </main>
  );
}
