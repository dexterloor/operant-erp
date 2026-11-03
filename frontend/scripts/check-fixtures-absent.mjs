// RED L-2 / b4-architecture.md Revision 1 "Tests": after `next build`, the preview fixtures (marker `__FIXTURE_ONLY__`,
// line 1 of every file in src/components/preview/fixtures) must not be in the production client bundle. Fails if the
// marker is found in `.next/static`, and fails if there is no build to check (a check that reads nothing is not a pass).
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import path from 'node:path';

const MARKER = '__FIXTURE_ONLY__';
const dir = path.resolve(process.argv[2] ?? '.next/static');

if (!existsSync(dir)) {
  console.error(`No build output at ${dir}: run next build first.`);
  process.exit(1);
}

const hits = [];
let scanned = 0;
const walk = (d) => {
  for (const name of readdirSync(d)) {
    const p = path.join(d, name);
    if (statSync(p).isDirectory()) walk(p);
    else {
      scanned += 1;
      if (readFileSync(p).includes(MARKER)) hits.push(path.relative(process.cwd(), p));
    }
  }
};
walk(dir);

if (scanned === 0) {
  console.error(`${dir} holds no files: nothing was checked.`);
  process.exit(1);
}
if (hits.length > 0) {
  console.error(`Preview fixtures are in the production bundle (${MARKER}):\n  ${hits.join('\n  ')}`);
  process.exit(1);
}
console.log(`No preview fixtures in the production bundle (${scanned} files checked).`);
