import { createHash } from 'node:crypto';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { describe, expect, it } from 'vitest';
// @ts-expect-error — plain ESM script without type declarations
import { CLIENT_OPS_PATH, CLIENT_PATH, CONTRACTS_DIR, SERVER_PATH, generate, readInputs } from '../../scripts/api-surface-lib.mjs';

// b3b-design.md §4 / b4-architecture.md Revision 5 A-7: the two generated modules are exactly what the committed
// contracts/ produce, so `npm run surface:check` and this test fail on a hand edit, on a contract change that was not
// regenerated, and on a missing input (never an empty surface).

const FRONTEND = path.resolve(__dirname, '../..');

describe('generated API surface (b3b §4, A-7)', () => {
  const inputs = readInputs();
  const out = generate(inputs);

  it('api-surface.server.ts is exactly what contracts/ generate (no hand edit, no drift)', () => {
    expect(readFileSync(SERVER_PATH, 'utf8')).toBe(out.server);
  });

  it('api-surface.client.ts is exactly what contracts/ generate', () => {
    expect(readFileSync(CLIENT_PATH, 'utf8')).toBe(out.client);
  });

  it('a changed contract changes both modules: the generator is not a constant', () => {
    const mutated = structuredClone(inputs);
    mutated.surface.bulk.max_ids = mutated.surface.bulk.max_ids + 1;
    // An operation the browser never calls (GetCurrentUser is read by the server layout only), so the wired list stays valid.
    mutated.openapi.paths['/api/v1/me'].get.operationId = 'GetCurrentUserX';
    mutated.surface.operations.GetCurrentUserX = mutated.surface.operations.GetCurrentUser;
    delete mutated.surface.operations.GetCurrentUser;
    const again = generate(mutated);
    expect(again.server).not.toBe(out.server);
    expect(again.client).not.toBe(out.client);
  });

  // Revision 7 §2 step 4.5 (RED L-3): the enum emitter's refusals, each driven.
  const CREATE_LOC = 'StorageLocation.CreateStorageLocationInput';
  const withKind = (edit: (kind: Record<string, unknown>) => void) => {
    const m = structuredClone(inputs);
    edit(m.openapi.components.schemas[CREATE_LOC].properties.kind);
    return m;
  };
  it('an empty enum is an error', () => {
    expect(() => generate(withKind((k) => (k.enum = [])))).toThrow(/non-empty list/);
  });
  it('a non-distinct or non-string enum is an error', () => {
    expect(() => generate(withKind((k) => (k.enum = ['bin', 'bin'])))).toThrow(/non-empty list of distinct strings/);
    expect(() => generate(withKind((k) => (k.enum = [1])))).toThrow(/non-empty list of distinct strings/);
  });
  it('no served default reaches the client projection, under any name (step 4.5 D-1: never preselected; RED-2 L-1)', () => {
    // The contract does serve one: the API's omitted-kind → bin behaviour is untouched (UX ruling).
    expect(inputs.openapi.components.schemas[CREATE_LOC].properties.kind.default).toBeDefined();
    // The projection's shape is pinned, not a name: its data keys and the module's exports are exactly these.
    const PROJECTION = ['BULK', 'BULK_MAX_IDS', 'CLIENT_OPERATIONS', 'WIRE_ENUMS', 'WIRE_FIELDS'];
    expect(Object.keys(out.clientData).sort()).toEqual(PROJECTION);
    expect([...out.client.matchAll(/^export const (\w+)/gm)].map((m) => m[1]).sort()).toEqual(PROJECTION);
    // And no emitted value object carries a `default` key.
    const hasDefault = (v: unknown): boolean => typeof v === 'object' && v !== null && (Object.prototype.hasOwnProperty.call(v, 'default') || Object.values(v).some(hasDefault));
    expect(hasDefault(out.clientData)).toBe(false);
    expect(hasDefault({ x: { y: { default: 'bin' } } })).toBe(true);
  });

  it('the update patch serves no default (BK-19 shape; RED-2 L-5(c), restored from the first build)', () => {
    expect(inputs.openapi.components.schemas['StorageLocation.UpdateStorageLocationInput.jsonMergePatch'].properties.kind.default).toBeUndefined();
  });

  it('records the sha256 of each input file, so a reader can tell which contract it was built from', () => {
    for (const name of ['openapi.json', 'api-surface.json']) {
      const sha = createHash('sha256').update(readFileSync(path.join(CONTRACTS_DIR, name))).digest('hex');
      expect(out.server).toContain(`'${name}': '${sha}'`);
    }
  });

  it('a missing input is an error, never an empty surface', () => {
    const empty = mkdtempSync(path.join(tmpdir(), 'surface-'));
    try {
      expect(() => readInputs(empty)).toThrow(/contracts/);
    } finally {
      rmSync(empty, { recursive: true, force: true });
    }
  });

  // An explicit timeout, and only here: this is the one test that starts two `node` processes, so its time is node's
  // start-up under whatever else the machine is running (≈70 ms each alone; it went past vitest's 5 s default with
  // three or four suites running at once). The work itself is the same small file comparison the tests above make.
  it('`surface:check` exits non-zero on a missing input, and zero on the committed tree', { timeout: 30_000 }, () => {
    const empty = mkdtempSync(path.join(tmpdir(), 'surface-'));
    try {
      const bad = spawnSync(process.execPath, ['scripts/build-api-surface.mjs', '--check', '--contracts', empty], { cwd: FRONTEND, encoding: 'utf8' });
      expect(bad.status).not.toBe(0);
      const good = spawnSync(process.execPath, ['scripts/build-api-surface.mjs', '--check'], { cwd: FRONTEND, encoding: 'utf8' });
      expect(good.status, good.stderr).toBe(0);
    } finally {
      rmSync(empty, { recursive: true, force: true });
    }
  });

  it('the client projection carries only the committed wired-operation list, and no /auth/ template', () => {
    const wired: string[] = JSON.parse(readFileSync(CLIENT_OPS_PATH, 'utf8'));
    expect(Object.keys(out.clientData.CLIENT_OPERATIONS).sort()).toEqual([...wired].sort());
    expect(out.client).not.toMatch(/\/api\/v1\/auth\//);
  });

  it('a wired operation the contract does not have is an error', () => {
    expect(() => generate(inputs, ['NoSuchOperation'])).toThrow(/NoSuchOperation/);
  });
});
