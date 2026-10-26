#!/usr/bin/env node
// Usage: node scripts/build-tokens.mjs          → writes src/styles/tokens.css and tokens.ts from tokens.json
//        node scripts/build-tokens.mjs --check  → exits 1 if either generated file differs from what tokens.json produces
import { readFileSync, writeFileSync } from 'node:fs';
import { loadTokens, generateCss, generateTs, CSS_PATH, TS_PATH } from './tokens-lib.mjs';

const t = loadTokens();
const targets = [
  [CSS_PATH, generateCss(t)],
  [TS_PATH, generateTs(t)],
];

if (process.argv.includes('--check')) {
  const drifted = targets.filter(([file, want]) => {
    try {
      return readFileSync(file, 'utf8') !== want;
    } catch {
      return true;
    }
  });
  if (drifted.length) {
    console.error(`Token drift: ${drifted.map(([f]) => f).join(', ')} differ from tokens.json. Run \`npm run tokens\`.`);
    process.exit(1);
  }
  console.log('tokens.css and tokens.ts match tokens.json.');
} else {
  for (const [file, want] of targets) writeFileSync(file, want);
  console.log('Wrote tokens.css and tokens.ts from tokens.json.');
}
