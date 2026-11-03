import 'server-only';
// The ONE reader of the generated server map (b4-architecture.md Revision 5 A-7, guard G-2). The proxy matches against
// proxyAllowlist(); apiUrl() validates against serverOperation(), which includes /api/v1/auth/*.
import { OPERATIONS, PROXY_ALLOWLIST, SOURCE_SHA256, type ProxyRoute, type SurfaceOperation } from '@/generated/api-surface.server';

export type { ProxyRoute, SurfaceOperation, SurfacePermission } from '@/generated/api-surface.server';

/** One operation from the full map. An unknown id is a programming error, so it throws. */
export function serverOperation(operationId: string): SurfaceOperation {
  const op = OPERATIONS[operationId];
  if (!op) throw new Error(`Operation ${operationId} is not in the contract.`);
  return op;
}

/** Every operation, keyed by operationId. */
export function allOperations(): Readonly<Record<string, SurfaceOperation>> {
  return OPERATIONS;
}

/** What the proxy may forward: method and template pairs. */
export function proxyAllowlist(): readonly ProxyRoute[] {
  return PROXY_ALLOWLIST;
}

/** The sha256 of each contract input the map was generated from. */
export function sourceSha256(): Readonly<Record<string, string>> {
  return SOURCE_SHA256;
}
