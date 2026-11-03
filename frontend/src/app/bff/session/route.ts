import { handleSignIn } from '@/lib/bff/server/handlers';

// POST /bff/session: a thin adapter over handleSignIn (b4-architecture.md Revision 5 A-7). No GET: nothing here reads.
export const dynamic = 'force-dynamic';

export function POST(request: Request): Promise<Response> {
  return handleSignIn(request);
}
