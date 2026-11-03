import { describe, expect, it } from 'vitest';
import { allOperations, proxyAllowlist } from '@/lib/bff/server/surface';

// C-4 (b3b §14, §4): the proxy allowlist is every /api/v1/ operation except /api/v1/auth/* and the operations a named
// handler owns (today ChangePassword, final RED L-7), so the proxy cannot bypass the handler's cookie handling. The
// half "an absent template answers 404 with zero API calls" needs the proxy and lands with it (C3).

const key = (o: { method: string; template: string }) => `${o.method} ${o.template}`;

describe('C-4 — PROXY_ALLOWLIST', () => {
  const allow = proxyAllowlist().map(key);
  const ops = allOperations();

  it('is non-empty and holds GET /api/v1/users/{id}', () => {
    expect(allow.length).toBeGreaterThan(0);
    expect(allow).toContain('GET /api/v1/users/{id}');
  });

  it('holds no /api/v1/auth/* operation and no ChangePassword', () => {
    expect(allow.filter((k) => k.includes(' /api/v1/auth/'))).toEqual([]);
    expect(ops.ChangePassword).toBeDefined();
    expect(allow).not.toContain(key(ops.ChangePassword!));
  });

  it('is exactly every operation minus /auth/* and the handler-owned ones; every entry is a real operation', () => {
    const expected = Object.entries(ops)
      .filter(([id, o]) => !o.template.startsWith('/api/v1/auth/') && id !== 'ChangePassword')
      .map(([, o]) => key(o))
      .sort();
    expect([...allow].sort()).toEqual(expected);
    expect(new Set(allow).size).toBe(allow.length);
  });

  it('the operation map keeps /auth/* (apiUrl validates against it) and every template is under /api/v1/', () => {
    expect(Object.values(ops).some((o) => o.template.startsWith('/api/v1/auth/'))).toBe(true);
    for (const o of Object.values(ops)) expect(o.template.startsWith('/api/v1/')).toBe(true);
  });

  it('path parameters are the names in each template, in order', () => {
    for (const [id, o] of Object.entries(ops)) {
      const inTemplate = [...o.template.matchAll(/\{([^}]+)\}/g)].map((m) => m[1]);
      expect(o.pathParams, id).toEqual(inTemplate);
    }
  });
});
