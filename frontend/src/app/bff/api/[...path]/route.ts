import { handleApiProxy } from '@/lib/bff/server/apiProxy';

// /bff/api/[...path]: a thin adapter over handleApiProxy (b4-architecture.md Revision 5 A-7). The handler reads the raw
// request path itself, never Next's decoded params.
export const dynamic = 'force-dynamic';

export const GET = (request: Request): Promise<Response> => handleApiProxy(request);
export const POST = GET;
export const PUT = GET;
export const PATCH = GET;
export const DELETE = GET;
