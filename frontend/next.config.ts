import type { NextConfig } from 'next';
import { isLoopbackBase, isLoopbackOrigin } from './src/lib/bff/loopback';

/**
 * Deployment guard, build-time half (b3b-design.md §5 item 18; ruling Q-7). This branch may only be built for a local
 * API: loading this file throws on any hosting platform's marker variable, and on an API base URL that is set and not
 * exact-host loopback, and on a public origin (APP_PUBLIC_ORIGIN) that is set and not exact-host loopback — a
 * self-hosted deployment (code RED M-3). An unset base or origin builds (the run-time half in lib/bff/server then
 * refuses the first API call and every write). Retired only by the commit that adds the first deployment configuration, which is gated on BK-2/3/4,
 * BK-8, BK-9, the idle bound (L3) and the owner's word. There is no marker file.
 */
const PLATFORM_MARKERS = ['VERCEL', 'VERCEL_ENV', 'NETLIFY', 'CF_PAGES'] as const;
for (const name of PLATFORM_MARKERS) {
  if (process.env[name] !== undefined) throw new Error(`Refusing a hosted build (${name} is set): no deployment configuration exists yet.`);
}
const apiBase = process.env.ERP_API_BASE_URL;
if (apiBase !== undefined && !isLoopbackBase(apiBase)) throw new Error('Refusing to build against an API base URL that is not loopback.');
const publicOrigin = process.env.APP_PUBLIC_ORIGIN;
if (publicOrigin !== undefined && !isLoopbackOrigin(publicOrigin)) throw new Error('Refusing to build for a public origin that is not loopback.');

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

/** No page of this app may be framed by another site (bff-auth-note.md R1-§3; b3b-design.md §5 item 13). */
export const FRAME_HEADERS = [
  { key: 'Content-Security-Policy', value: "frame-ancestors 'none'" },
  { key: 'X-Frame-Options', value: 'DENY' },
] as const;

const nextConfig: NextConfig = {
  reactStrictMode: true,
  poweredByHeader: false,
  // Next 16.4's `next dev` otherwise writes an AGENTS.md into this folder; this project does not want that file.
  agentRules: false,
  async headers() {
    return [
      { source: '/:path*', headers: FRAME_HEADERS.map((h) => ({ ...h })) },
      ...CREDENTIAL_PAGE_ROUTES.map((source) => ({ source, headers: CREDENTIAL_PAGE_HEADERS.map((h) => ({ ...h })) })),
    ];
  },
};

export default nextConfig;
