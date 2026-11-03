// The ONE reader of the generated client projection (b4-architecture.md Revision 5 A-7, guard G-2). Everything the
// browser knows about the API's shape — which operations are wired, their templates and gates, the bulk resources and
// cap, the served field names — comes through here, never typed by hand (G-3 refuses a typed bulk key or cap).
import { BULK, BULK_MAX_IDS, CLIENT_OPERATIONS, WIRE_FIELDS, type BulkResource, type SurfaceOperation, type SurfacePermission } from '@/generated/api-surface.client';

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

/**
 * Whether a viewer holding `codes` may call a wired operation (b3b-design.md §9 "The gate helper", final RED L-2): the
 * gate is read from the contract, never typed. `all_of` → every code held; `none` → open; `derived` or any unknown kind →
 * refused.
 */
export function viewerMay(operationId: string, codes: ReadonlySet<string> | readonly string[]): boolean {
  return gateAllows(operation(operationId).permission, codes);
}

/** The rule itself, over one served permission object. */
export function gateAllows(p: SurfacePermission, codes: ReadonlySet<string> | readonly string[]): boolean {
  const held = codes instanceof Set ? codes : new Set(codes as readonly string[]);
  if (p.kind === 'none') return true;
  if (p.kind === 'all_of') return p.codes.every((c) => held.has(c));
  return false;
}

/** The one code an `all_of` gate of a single code names, for the ST-4b sentence; null otherwise. */
export function gateCode(operationId: string): string | null {
  const p = operation(operationId).permission;
  return p.kind === 'all_of' && p.codes.length === 1 ? p.codes[0]! : null;
}
