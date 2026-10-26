#!/usr/bin/env node
// Prints every contrast pair for both themes and exits 1 if any falls below its minimum.
import { loadTokens } from './tokens-lib.mjs';
import { report } from './contrast-lib.mjs';

const rows = report(loadTokens());
const failed = rows.filter((r) => !r.pass);
const verbose = process.argv.includes('--all');
for (const r of rows) {
  if (verbose || !r.pass) console.log(`${r.pass ? 'ok  ' : 'FAIL'} ${r.theme.padEnd(5)} ${r.kind.padEnd(10)} ${r.fg.padEnd(18)} on ${r.bg.padEnd(18)} ${r.value.toFixed(2)} (min ${r.min})`);
}
const byKind = {};
for (const r of rows) {
  const k = `${r.theme} ${r.kind}`;
  byKind[k] ||= { n: 0, min: Infinity };
  byKind[k].n += 1;
  byKind[k].min = Math.min(byKind[k].min, r.value);
}
for (const [k, v] of Object.entries(byKind)) console.log(`${k}: ${v.n} pairs, lowest ${v.min.toFixed(2)}`);
console.log(failed.length ? `${failed.length} of ${rows.length} pairs FAIL.` : `All ${rows.length} pairs pass.`);
process.exit(failed.length ? 1 : 0);
