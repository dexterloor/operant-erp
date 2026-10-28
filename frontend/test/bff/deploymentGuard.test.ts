// @vitest-environment node
import { afterEach, describe, expect, it, vi } from 'vitest';

// T-DG, build-time half (b3b-design.md §5 item 18; ruling Q-7): loading next.config.ts IS the build's first step, so
// the guard is tested by loading the real config. The run-time half is in test/bff/apiClient.test.ts.

const PLATFORM = ['VERCEL', 'VERCEL_ENV', 'NETLIFY', 'CF_PAGES'] as const;

async function loadConfig() {
  vi.resetModules();
  return import('../../next.config');
}

afterEach(() => vi.unstubAllEnvs());

function clearAll() {
  for (const v of [...PLATFORM, 'ERP_API_BASE_URL', 'CI']) vi.stubEnv(v, undefined as unknown as string);
}

describe('T-DG — the build refuses a hosted build and a non-loopback API', () => {
  it.each(PLATFORM)('%s set → the build throws', async (name) => {
    clearAll();
    vi.stubEnv(name, '1');
    await expect(loadConfig()).rejects.toThrow(/deployment/i);
  });

  it.each(['https://api.example.com', 'http://localhost.evil.example', 'http://127.0.0.1.nip.io', 'nonsense'])(
    'ERP_API_BASE_URL=%s set and not loopback → the build throws',
    async (base) => {
      clearAll();
      vi.stubEnv('ERP_API_BASE_URL', base);
      await expect(loadConfig()).rejects.toThrow(/loopback/i);
    },
  );

  it('nothing set → the build passes (the run-time half refuses the first API call instead)', async () => {
    clearAll();
    await expect(loadConfig()).resolves.toBeDefined();
  });

  it.each(['http://127.0.0.1:8082', 'http://localhost:8082', 'https://[::1]:9'])('loopback %s → the build passes', async (base) => {
    clearAll();
    vi.stubEnv('ERP_API_BASE_URL', base);
    await expect(loadConfig()).resolves.toBeDefined();
  });

  it('CI=true alone → the build passes', async () => {
    clearAll();
    vi.stubEnv('CI', 'true');
    await expect(loadConfig()).resolves.toBeDefined();
  });

  it('a platform variable that is present but empty still counts as set (fail closed)', async () => {
    clearAll();
    vi.stubEnv('VERCEL', '');
    await expect(loadConfig()).rejects.toThrow(/deployment/i);
  });
});
