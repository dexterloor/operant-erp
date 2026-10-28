import { handleResetRequest } from '@/lib/bff/server/handlers';

// POST /bff/password-resets: a thin adapter over handleResetRequest (b4-architecture.md Revision 5 A-7).
export const dynamic = 'force-dynamic';

export function POST(request: Request): Promise<Response> {
  return handleResetRequest(request);
}
