// @vitest-environment node
import { readdirSync, readFileSync, statSync } from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';
import nextConfig, { CREDENTIAL_PAGE_HEADERS, CREDENTIAL_PAGE_ROUTES, FRAME_HEADERS } from '../../next.config';

const APP_DIR = path.resolve(__dirname, '../../src/app');
const PASSWORD_COPY_IMPORT = /from\s+['"]@\/copy\/password['"]/;

/** Every page under src/app (route groups stripped) whose page.tsx imports the password copy: the pages that ask for a
 * password or an email to reset one. Derived from disk, so a new password page cannot be missed by a hand-list. */
function pagesUsingPasswordCopy(dir = APP_DIR, rel = ''): string[] {
  const out: string[] = [];
  for (const name of readdirSync(dir).sort()) {
    const abs = path.join(dir, name);
    if (statSync(abs).isDirectory()) out.push(...pagesUsingPasswordCopy(abs, rel + (/^\(.*\)$/.test(name) ? '' : `/${name}`)));
    else if (name === 'page.tsx' && PASSWORD_COPY_IMPORT.test(readFileSync(abs, 'utf8'))) out.push(rel === '' ? '/' : rel);
  }
  return out;
}

function mismatch(declared: readonly string[], derived: readonly string[]) {
  return { notDeclared: derived.filter((r) => !declared.includes(r)), notAPasswordPage: declared.filter((r) => !derived.includes(r)) };
}

// T-X / T-5 framing (b3b-design.md §5 item 13; bff-auth-note.md R1-§3): frame-ancestors 'none' and X-Frame-Options DENY
// on every route, set once in next.config.ts headers(); the credential pages keep their own headers.
describe('framing headers on every route', () => {
  it('a catch-all source carries both framing headers', async () => {
    const rules = await nextConfig.headers!();
    const all = rules.find((r) => r.source === '/:path*');
    expect(all).toBeDefined();
    expect(all!.headers).toEqual(expect.arrayContaining([...FRAME_HEADERS]));
    expect(FRAME_HEADERS).toEqual([
      { key: 'Content-Security-Policy', value: "frame-ancestors 'none'" },
      { key: 'X-Frame-Options', value: 'DENY' },
    ]);
  });

  it('the credential pages keep Referrer-Policy and no-store', async () => {
    const rules = await nextConfig.headers!();
    for (const route of CREDENTIAL_PAGE_ROUTES) {
      const r = rules.find((x) => x.source === route)!;
      expect(r.headers).toEqual(CREDENTIAL_PAGE_HEADERS.map((h) => ({ ...h })));
    }
  });

  it('CREDENTIAL_PAGE_ROUTES is every page built from the password copy, both ways', () => {
    const derived = pagesUsingPasswordCopy();
    expect(derived).toEqual(expect.arrayContaining(['/forgot-password', '/reset-password', '/account/password']));
    expect(mismatch(CREDENTIAL_PAGE_ROUTES, derived)).toEqual({ notDeclared: [], notAPasswordPage: [] });
  });

  it('planted control: a password page missing from the list, or a listed route that is not one, is red', () => {
    const derived = pagesUsingPasswordCopy();
    expect(mismatch(derived, [...derived, '/account/planted']).notDeclared).toEqual(['/account/planted']);
    expect(mismatch([...derived, '/ghost'], derived).notAPasswordPage).toEqual(['/ghost']);
  });
});
