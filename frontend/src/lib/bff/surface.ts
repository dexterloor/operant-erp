// The ONE reader of the generated client projection (b4-architecture.md Revision 5 A-7, guard G-2). Everything the
// browser knows about the API's shape — which operations are wired, their templates and gates, the bulk resources and
// cap, the served field names — comes through here, never typed by hand (G-3 refuses a typed bulk key or cap).
import { BULK, BULK_MAX_IDS, CLIENT_OPERATIONS, WIRE_FIELDS, type BulkResource, type SurfaceOperation } from '@/generated/api-surface.client';

export type { BulkResource, SurfaceOperation, SurfacePermission } from '@/generated/api-surface.client';

/** A wired operation. An operation that is not wired is a programming error, so it throws rather than returning nothing. */
export function operation(operationId: string): SurfaceOperation {
  const op = CLIENT_OPERATIONS[operationId];
  if (!op) throw new Error(`Operation ${operationId} is not wired (scripts/api-surface.client-ops.json).`);
  return op;
}

/** The resource keys that can be archived or restored in bulk, sorted. */
export function bulkResources(): string[] {
  return Object.keys(BULK).sort();
}

/** One bulk resource's operations and gate. */
export function bulkFor(resource: string): BulkResource {
  const b = BULK[resource];
  if (!b) throw new Error(`${resource} cannot be archived or restored in bulk.`);
  return b;
}

/** The most ids one bulk request may carry. */
export function bulkMaxIds(): number {
  return BULK_MAX_IDS;
}

/** The names of the schemas a hand-written lib/api type mirrors. */
export function mirroredSchemas(): string[] {
  return Object.keys(WIRE_FIELDS);
}

/** Sorted served property names of one mirrored schema. */
export function wireFields(name: string): string[] {
  const f = WIRE_FIELDS[name];
  if (!f) throw new Error(`${name} is not a mirrored schema.`);
  return [...f];
}
