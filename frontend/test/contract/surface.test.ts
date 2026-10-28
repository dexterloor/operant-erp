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

  it('`surface:check` exits non-zero on a missing input, and zero on the committed tree', () => {
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
