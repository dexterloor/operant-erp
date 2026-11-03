import { handleResetRedemption } from '@/lib/bff/server/handlers';

// POST /bff/password-resets/redemption: a thin adapter over handleResetRedemption. Never the proxy (bff-auth-note.md §8).
export const dynamic = 'force-dynamic';

export function POST(request: Request): Promise<Response> {
  return handleResetRedemption(request);
}
