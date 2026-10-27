import type { NextConfig } from 'next';

/**
 * Response headers for the two password-reset pages (ux-copy-frontend.md §1.3–§1.4, bff-auth-note.md §8 / R1-§8):
 * no referrer may carry anything off these pages, and neither may be stored by a browser or a shared cache. Both pages
 * are also rendered per request (`dynamic = 'force-dynamic'`), which is what makes Next itself send `no-store`; the
 * explicit header here is the declared rule a test reads.
 */
export const CREDENTIAL_PAGE_HEADERS = [
  { key: 'Referrer-Policy', value: 'no-referrer' },
  { key: 'Cache-Control', value: 'no-store' },
] as const;
export const CREDENTIAL_PAGE_ROUTES = ['/forgot-password', '/reset-password'] as const;

// B-3a: components only. No rewrites, no API proxy, no BFF route handlers yet (B-3b).
const nextConfig: NextConfig = {
  reactStrictMode: true,
  poweredByHeader: false,
  // Next 16.4's `next dev` otherwise writes an AGENTS.md into this folder; this project does not want that file.
  agentRules: false,
  async headers() {
    return CREDENTIAL_PAGE_ROUTES.map((source) => ({ source, headers: CREDENTIAL_PAGE_HEADERS.map((h) => ({ ...h })) }));
  },
};

export default nextConfig;
