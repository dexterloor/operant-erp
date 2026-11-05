// The web app's view of the API (b3b-design.md §4; b4-architecture.md Revision 5 A-7). The two committed files under
// ../contracts/ are the only source: openapi.json (the served spec) and api-surface.json (gates, Idempotency-Key, bulk).
// This module turns them into two generated TypeScript modules:
//   src/generated/api-surface.server.ts  OPERATIONS (every operation, /auth/* included), PROXY_ALLOWLIST, SOURCE_SHA256
//   src/generated/api-surface.client.ts  CLIENT_OPERATIONS (only the wired ones), BULK, BULK_MAX_IDS, WIRE_FIELDS
// Pure functions only, so the drift test can call them without touching the disk. Everything that depends on the
// contract's key names or on API Platform's schema names lives here and nowhere else (b3b §4 items 1–5).
import { createHash } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const FRONTEND_DIR = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
export const CONTRACTS_DIR = path.resolve(FRONTEND_DIR, '../contracts');
export const SERVER_PATH = path.join(FRONTEND_DIR, 'src/generated/api-surface.server.ts');
export const CLIENT_PATH = path.join(FRONTEND_DIR, 'src/generated/api-surface.client.ts');
export const CLIENT_OPS_PATH = path.join(FRONTEND_DIR, 'scripts/api-surface.client-ops.json');

const INPUTS = ['openapi.json', 'api-surface.json'];
const METHODS = ['get', 'post', 'put', 'patch', 'delete'];
const API_PREFIX = '/api/v1/';
const AUTH_PREFIX = '/api/v1/auth/';

/**
 * Operations a named BFF handler owns. The proxy must not reach them, or it would bypass the handler (today:
 * ChangePassword, whose handler deletes the session cookie; final RED L-7).
 */
const HANDLER_OWNED = ['ChangePassword'];

/**
 * The hand-written lib/api types the C-1 parity test checks, and where each one's served schema sits in openapi.json.
 * A path is a list of keys from `components.schemas`; inline schemas (no component) are reached through their list.
 */
const MIRRORED = {
  User: ['User'],
  RoleReference: ['RoleReference'],
  'User.roles[]': ['User', 'properties', 'roles', 'items'],
  Role: ['Role'],
  RoleGrantProposal: ['RoleGrantProposal'],
  UserDisplayReference: ['UserDisplayReference'],
  Permission: ['PermissionCollection', 'properties', 'items', 'items'],
  SodRowRef: ['PermissionCollection', 'properties', 'items', 'items', 'properties', 'sod_rows', 'items'],
  OfferedRole: ['OfferedRoleCollection', 'properties', 'items', 'items'],
  RoleConflict: ['OfferedRoleCollection', 'properties', 'items', 'items', 'properties', 'conflicts_with', 'items'],
  Collection: ['RoleCollection'],
  Warehouse: ['Warehouse'],
  'Warehouse.UpdateWarehouseInput.jsonMergePatch': ['Warehouse.UpdateWarehouseInput.jsonMergePatch'],
};

const BANNER =
  'GENERATED from ../contracts/ by scripts/build-api-surface.mjs. Do not edit by hand: run `npm run surface`. `npm run surface:check` (part of `npm run check`) fails on any difference.';

/** Reads both inputs and the wired-operation list. A missing input throws: there is never an empty surface. */
export function readInputs(dir = CONTRACTS_DIR, clientOpsPath = CLIENT_OPS_PATH) {
  for (const name of INPUTS) {
    if (!existsSync(path.join(dir, name))) throw new Error(`contracts input missing: ${path.join(dir, name)}`);
  }
  const raw = Object.fromEntries(INPUTS.map((name) => [name, readFileSync(path.join(dir, name))]));
  return {
    openapi: JSON.parse(raw['openapi.json'].toString('utf8')),
    surface: JSON.parse(raw['api-surface.json'].toString('utf8')),
    sha256: Object.fromEntries(INPUTS.map((name) => [name, createHash('sha256').update(raw[name]).digest('hex')])),
    clientOps: JSON.parse(readFileSync(clientOpsPath, 'utf8')),
  };
}

const sortedKeys = (o) => Object.keys(o).sort();

function operationsOf(openapi, surface) {
  const ops = {};
  for (const template of sortedKeys(openapi.paths)) {
    if (!template.startsWith(API_PREFIX)) throw new Error(`template outside ${API_PREFIX}: ${template}`);
    for (const method of METHODS) {
      const op = openapi.paths[template][method];
      if (!op) continue;
      const id = op.operationId;
      if (!id) throw new Error(`no operationId: ${method} ${template}`);
      if (ops[id]) throw new Error(`duplicate operationId: ${id}`);
      const facts = surface.operations[id];
      if (!facts) throw new Error(`api-surface.json has no entry for ${id}`);
      const pathParams = [...template.matchAll(/\{([^}]+)\}/g)].map((m) => m[1]);
      for (const p of pathParams) {
        if (!surface.pathParameters.names.includes(p)) throw new Error(`${id}: path parameter ${p} is not a declared name`);
      }
      const permission = facts.permission.kind === 'all_of' ? { kind: 'all_of', codes: [...facts.permission.codes] } : { kind: facts.permission.kind };
      ops[id] = { method: method.toUpperCase(), template, pathParams, idempotencyKeyRequired: facts.idempotency_key_required === true, permission };
    }
  }
  const missing = sortedKeys(surface.operations).filter((id) => !ops[id]);
  if (missing.length) throw new Error(`api-surface.json names operations the spec does not: ${missing.join(', ')}`);
  return Object.fromEntries(sortedKeys(ops).map((id) => [id, ops[id]]));
}

function schemaAt(openapi, trail) {
  let node = openapi.components.schemas;
  for (const k of trail) {
    node = node?.[k];
    if (node === undefined) throw new Error(`schema path not found: ${trail.join('.')}`);
  }
  if (!node.properties) throw new Error(`schema has no properties: ${trail.join('.')}`);
  return node;
}

/** The plain data both modules print; exported so tests can check it without parsing TypeScript. */
export function surfaceData(inputs, clientOps = inputs.clientOps) {
  const { openapi, surface } = inputs;
  const OPERATIONS = operationsOf(openapi, surface);
  const PROXY_ALLOWLIST = Object.entries(OPERATIONS)
    .filter(([id, o]) => !o.template.startsWith(AUTH_PREFIX) && !HANDLER_OWNED.includes(id))
    .map(([, o]) => ({ method: o.method, template: o.template }))
    .sort((a, b) => (a.template === b.template ? a.method.localeCompare(b.method) : a.template < b.template ? -1 : 1));
  const CLIENT_OPERATIONS = {};
  for (const id of [...clientOps].sort()) {
    const o = OPERATIONS[id];
    if (!o) throw new Error(`wired operation not in the contract: ${id}`);
    if (o.template.startsWith(AUTH_PREFIX)) throw new Error(`an /auth/ operation never reaches the client: ${id}`);
    CLIENT_OPERATIONS[id] = o;
  }
  const BULK = Object.fromEntries(
    sortedKeys(surface.bulk.resources).map((r) => {
      const b = surface.bulk.resources[r];
      return [r, { list: b.list, delete: b.delete, restore: b.restore, permission: b.permission }];
    }),
  );
  const BULK_MAX_IDS = surface.bulk.max_ids;
  if (!Number.isInteger(BULK_MAX_IDS) || BULK_MAX_IDS < 1) throw new Error('bulk.max_ids must be a positive integer');
  const WIRE_FIELDS = Object.fromEntries(sortedKeys(MIRRORED).map((name) => [name, sortedKeys(schemaAt(openapi, MIRRORED[name]).properties)]));
  return {
    server: { OPERATIONS, PROXY_ALLOWLIST, SOURCE_SHA256: inputs.sha256 },
    client: { CLIENT_OPERATIONS, BULK, BULK_MAX_IDS, WIRE_FIELDS },
  };
}

/** A value as TypeScript source: single-quoted strings, sorted object keys already applied by the caller. */
function ts(value, indent = '') {
  const next = `${indent}  `;
  if (value === null) return 'null';
  if (typeof value === 'string') return `'${value.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
  if (typeof value === 'number' || typeof value === 'boolean') return String(value);
  if (Array.isArray(value)) {
    if (value.length === 0) return '[]';
    if (value.every((v) => typeof v !== 'object' || v === null)) return `[${value.map((v) => ts(v)).join(', ')}]`;
    return `[\n${value.map((v) => `${next}${ts(v, next)},`).join('\n')}\n${indent}]`;
  }
  const entries = Object.entries(value);
  if (entries.length === 0) return '{}';
  const key = (k) => (/^[A-Za-z_$][A-Za-z0-9_$]*$/.test(k) ? k : ts(k));
  return `{\n${entries.map(([k, v]) => `${next}${key(k)}: ${ts(v, next)},`).join('\n')}\n${indent}}`;
}

const OPERATION_TYPES = `export type SurfacePermission = { readonly kind: 'all_of'; readonly codes: readonly string[] } | { readonly kind: 'none' } | { readonly kind: 'derived' };

export interface SurfaceOperation {
  readonly method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  readonly template: string;
  /** Path variable names in template order; each is a record id (contracts pathParameters.format). */
  readonly pathParams: readonly string[];
  readonly idempotencyKeyRequired: boolean;
  readonly permission: SurfacePermission;
}
`;

/** Both module texts and the data behind them. `clientOps` overrides the committed wired list (tests only). */
export function generate(inputs, clientOps = inputs.clientOps) {
  const data = surfaceData(inputs, clientOps);
  const server = `// ${BANNER}
// Server only: read by src/lib/bff/server/surface.ts and nothing else (G-2). Imports nothing.

${OPERATION_TYPES}
export interface ProxyRoute {
  readonly method: SurfaceOperation['method'];
  readonly template: string;
}

/** Every operation in the contract, /api/v1/auth/* included: apiUrl() validates against this. */
export const OPERATIONS: Readonly<Record<string, SurfaceOperation>> = ${ts(data.server.OPERATIONS)};

/** Every /api/v1/ operation except /api/v1/auth/* and the ones a named handler owns: what the proxy may forward. */
export const PROXY_ALLOWLIST: readonly ProxyRoute[] = ${ts(data.server.PROXY_ALLOWLIST)};

/** The sha256 of each contract input this module was generated from. */
export const SOURCE_SHA256: Readonly<Record<string, string>> = ${ts(data.server.SOURCE_SHA256)};
`;
  const client = `// ${BANNER}
// Client projection: read by src/lib/bff/surface.ts and nothing else (G-2). Imports nothing. Holds only the operations
// listed in scripts/api-surface.client-ops.json; no sign-in or reset (auth) template reaches the browser.

${OPERATION_TYPES}
export interface BulkResource {
  readonly list: string;
  readonly delete: string;
  readonly restore: string;
  /** The one code the bulk gate checks for this resource. */
  readonly permission: string;
}

/** The wired operations only. */
export const CLIENT_OPERATIONS: Readonly<Record<string, SurfaceOperation>> = ${ts(data.client.CLIENT_OPERATIONS)};

/** The resources that can be archived or restored in bulk. */
export const BULK: Readonly<Record<string, BulkResource>> = ${ts(data.client.BULK)};

/** The most ids one bulk request may carry. */
export const BULK_MAX_IDS: number = ${ts(data.client.BULK_MAX_IDS)};

/** Sorted served property names of each schema a hand-written lib/api type mirrors (checked by test C-1). */
export const WIRE_FIELDS: Readonly<Record<string, readonly string[]>> = ${ts(data.client.WIRE_FIELDS)};
`;
  return { server, client, serverData: data.server, clientData: data.client };
}
