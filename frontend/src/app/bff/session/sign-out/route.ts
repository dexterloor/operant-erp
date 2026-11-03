import { handleSignOut } from '@/lib/bff/server/handlers';

// POST /bff/session/sign-out: a thin adapter over handleSignOut. Sign-out is a POST, never a link (bff-auth-note.md §3).
export const dynamic = 'force-dynamic';

export function POST(request: Request): Promise<Response> {
  return handleSignOut(request);
}
