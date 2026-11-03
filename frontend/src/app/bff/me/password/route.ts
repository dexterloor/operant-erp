import { handleChangePassword } from '@/lib/bff/server/handlers';

// POST /bff/me/password: a thin adapter over handleChangePassword. Never the proxy (bff-auth-note.md §8; the proxy's
// allowlist excludes ChangePassword).
export const dynamic = 'force-dynamic';

export function POST(request: Request): Promise<Response> {
  return handleChangePassword(request);
}
