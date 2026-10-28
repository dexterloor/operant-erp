// @vitest-environment node
import { describe, expect, it } from 'vitest';
import nextConfig, { CREDENTIAL_PAGE_HEADERS, CREDENTIAL_PAGE_ROUTES, FRAME_HEADERS } from '../../next.config';

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
});
