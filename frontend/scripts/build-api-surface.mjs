#!/usr/bin/env node
// Usage: node scripts/build-api-surface.mjs                  → writes src/generated/api-surface.{server,client}.ts
//        node scripts/build-api-surface.mjs --check          → exits 1 if either differs from what ../contracts/ produce
//        … --contracts <dir>                                  → reads the contract files from <dir> instead
// A missing input always fails, in both modes.
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { CLIENT_PATH, CONTRACTS_DIR, SERVER_PATH, generate, readInputs } from './api-surface-lib.mjs';

const args = process.argv.slice(2);
const at = args.indexOf('--contracts');
const dir = at >= 0 ? args[at + 1] : CONTRACTS_DIR;
if (at >= 0 && !dir) {
  console.error('--contracts needs a directory.');
  process.exit(1);
}

let out;
try {
  out = generate(readInputs(dir));
} catch (e) {
  console.error(`API surface: ${e.message}`);
  process.exit(1);
}

const targets = [
  [SERVER_PATH, out.server],
  [CLIENT_PATH, out.client],
];

if (args.includes('--check')) {
  const drifted = targets.filter(([file, want]) => {
    try {
      return readFileSync(file, 'utf8') !== want;
    } catch {
      return true;
    }
  });
  if (drifted.length) {
    console.error(`API surface drift: ${drifted.map(([f]) => path.relative(process.cwd(), f)).join(', ')} differ from contracts/. Run \`npm run surface\`.`);
    process.exit(1);
  }
  console.log('api-surface.server.ts and api-surface.client.ts match contracts/.');
} else {
  mkdirSync(path.dirname(SERVER_PATH), { recursive: true });
  for (const [file, want] of targets) writeFileSync(file, want);
  console.log('Wrote api-surface.server.ts and api-surface.client.ts from contracts/.');
}
