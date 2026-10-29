import type { Metadata } from 'next';
import { CHANGE_PASSWORD } from '@/copy/password';
import { ChangePasswordRoute } from './ChangePasswordRoute';

export const dynamic = 'force-dynamic';

export const metadata: Metadata = { title: CHANGE_PASSWORD.title };

/**
 * `/account/password` inside the signed-in frame (b4-architecture.md Revision 6 A-13): reached from the user menu, never
 * a navigation item. The frame provides the one `<main>`, so this page renders none.
 */
export default function Page() {
  return <ChangePasswordRoute />;
}
