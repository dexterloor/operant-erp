import type { NextConfig } from 'next';

// B-3a: components only. No rewrites, no API proxy, no BFF route handlers yet (B-3b).
const nextConfig: NextConfig = {
  reactStrictMode: true,
  poweredByHeader: false,
  // Next 16.4's `next dev` otherwise writes an AGENTS.md into this folder; this project does not want that file.
  agentRules: false,
};

export default nextConfig;
