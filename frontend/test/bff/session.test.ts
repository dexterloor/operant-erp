// @vitest-environment node
import { compactDecrypt } from 'jose';
import { describe, expect, it } from 'vitest';
import {
  SESSION_COOKIE,
  clearedSessionCookie,
  clientSessionView,
  keyringFrom,
  newSessionPayload,
  sealSession,
  sessionCookie,
  tokenOf,
  unsealSession,
  validTimeZone,
} from '@/lib/bff/server/session';

// T-S and T-TK (a) (b3b-design.md §14; bff-auth-note.md §2, R1-§2, R1-§2a L2). Node environment: the seal is WebCrypto.

const KEY_A = Buffer.from(Array.from({ length: 32 }, (_, i) => (i * 7 + 1) % 256)).toString('base64url');
const KEY_B = Buffer.from(Array.from({ length: 32 }, (_, i) => (i * 7 + 2) % 256)).toString('base64url');
const ring = keyringFrom(`a:${KEY_A}`);
const NOW = 1_800_000_000;
const JWT = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJ4In0.c2lnbmF0dXJlLXNlZ21lbnQtdW5pcXVl';

const payload = (over: Partial<Parameters<typeof newSessionPayload>[0]> = {}) =>
  newSessionPayload({ jwt: JWT, exp: NOW + 600, workspaceTyped: 'acme', timeZone: 'Europe/Lisbon', ...over });

describe('T-S — the sealed session', () => {
  it('seals and opens; the opened session carries sid, exp, workspace and tz, and the token only through tokenOf', async () => {
    const p = payload();
    const sealed = await sealSession(p, ring);
    expect(sealed).not.toContain(JWT);
    const s = await unsealSession(sealed, ring, NOW);
    expect(s).not.toBeNull();
    expect(s!.sid).toBe(p.sid);
    expect(s!.tz).toBe('Europe/Lisbon');
    expect(s!.workspaceTyped).toBe('acme');
    expect(s!.exp).toBe(NOW + 600);
    expect(tokenOf(s!)).toBe(JWT);
    expect(JSON.stringify(s)).not.toContain('c2lnbmF0dXJl');
    expect(Object.keys(s!)).not.toContain('jwt');
  });

  it('the payload shape is exactly {v, sid, jwt, exp, workspace_typed, tz} (L-10: unchanged from C2 on)', async () => {
    const sealed = await sealSession(payload(), ring);
    const { plaintext, protectedHeader } = await compactDecrypt(sealed, Buffer.from(KEY_A, 'base64url'));
    expect(protectedHeader).toMatchObject({ alg: 'dir', enc: 'A256GCM', kid: 'a' });
    const body = JSON.parse(new TextDecoder().decode(plaintext));
    expect(Object.keys(body).sort()).toEqual(['exp', 'jwt', 'sid', 'tz', 'v', 'workspace_typed']);
    expect(body.v).toBe('a');
  });

  it('a tampered cookie (one character changed) is no session', async () => {
    const sealed = await sealSession(payload(), ring);
    const i = sealed.length - 5;
    const flipped = sealed.slice(0, i) + (sealed[i] === 'A' ? 'B' : 'A') + sealed.slice(i + 1);
    expect(await unsealSession(flipped, ring, NOW)).toBeNull();
    expect(await unsealSession('not-a-jwe', ring, NOW)).toBeNull();
    expect(await unsealSession('', ring, NOW)).toBeNull();
  });

  it('past its sealed exp it is no session (the BFF never asks the API)', async () => {
    const sealed = await sealSession(payload({ exp: NOW + 10 }), ring);
    expect(await unsealSession(sealed, ring, NOW + 9)).not.toBeNull();
    expect(await unsealSession(sealed, ring, NOW + 10)).toBeNull();
  });

  it('key rotation: seals with the newest key; opens with any listed key; an unknown key id is no session', async () => {
    const old = await sealSession(payload(), keyringFrom(`a:${KEY_A}`));
    const rotated = keyringFrom(`b:${KEY_B},a:${KEY_A}`);
    expect(await unsealSession(old, rotated, NOW)).not.toBeNull();
    const fresh = await sealSession(payload(), rotated);
    const header = JSON.parse(Buffer.from(fresh.split('.')[0]!, 'base64url').toString());
    expect(header.kid).toBe('b');
    expect(await unsealSession(old, keyringFrom(`b:${KEY_B}`), NOW)).toBeNull();
  });

  it('the key ring refuses a key that is not 32 bytes, a missing value and duplicate ids', () => {
    expect(() => keyringFrom(undefined)).toThrow();
    expect(() => keyringFrom('')).toThrow();
    expect(() => keyringFrom(`a:${Buffer.alloc(16).toString('base64url')}`)).toThrow();
    expect(() => keyringFrom(`a:${KEY_A},a:${KEY_B}`)).toThrow();
  });

  it('a fresh sid per sign-in', () => {
    expect(payload().sid).not.toBe(payload().sid);
    expect(payload().sid).toMatch(/^[0-9a-f-]{36}$/);
  });

  it('tz is validated: a usable IANA zone ≤ 64 chars is kept; anything else becomes UTC', () => {
    expect(validTimeZone('Europe/Lisbon')).toBe('Europe/Lisbon');
    expect(validTimeZone('Asia/Manila')).toBe('Asia/Manila');
    for (const bad of [undefined, null, '', 'Not/AZone', 42, 'A'.repeat(65), {}]) expect(validTimeZone(bad)).toBe('UTC');
    expect(payload({ timeZone: 'nonsense' }).tz).toBe('UTC');
  });
});

describe('T-S — cookie attributes (T-1; L2: no Expires, no Max-Age)', () => {
  it('sets __Host-erp_session, HttpOnly, Secure, SameSite=Lax, Path=/, no Domain, no Expires or Max-Age', () => {
    const c = sessionCookie('v.a.l.u.e');
    expect(SESSION_COOKIE).toBe('__Host-erp_session');
    const parts = c.split('; ');
    expect(parts[0]).toBe('__Host-erp_session=v.a.l.u.e');
    expect(parts.slice(1).sort()).toEqual(['HttpOnly', 'Path=/', 'SameSite=Lax', 'Secure']);
    expect(c).not.toMatch(/Domain|Expires|Max-Age/i);
  });

  it('deletion uses the same name and attributes with an expiry in the past', () => {
    const c = clearedSessionCookie();
    expect(c).toMatch(/^__Host-erp_session=;/);
    expect(c).toContain('Path=/');
    expect(c).toContain('Secure');
    expect(c).toContain('HttpOnly');
    expect(c).toContain('Max-Age=0');
    expect(c).toContain('Expires=Thu, 01 Jan 1970 00:00:00 GMT');
  });
});

describe('T-TK (a) — the client view of a session is exactly {sid, tz}', () => {
  it('returns only sid and tz, and the token signature segment is nowhere in it', async () => {
    const s = await unsealSession(await sealSession(payload(), ring), ring, NOW);
    const view = clientSessionView(s!);
    expect(Object.keys(view).sort()).toEqual(['sid', 'tz']);
    const signature = JWT.split('.')[2]!;
    expect(JSON.stringify(view)).not.toContain(signature);
    expect(JSON.stringify(view)).not.toContain('acme');
  });
});

describe('code RED L-7 — key quality', () => {
  it.each([0, 1, 7, 255])('a key whose 32 bytes are all %i is refused, without printing it', (b) => {
    const value = Buffer.alloc(32, b).toString('base64url');
    let message = '';
    try {
      keyringFrom(`k:${value}`);
    } catch (e) {
      message = (e as Error).message;
    }
    expect(message).toMatch(/one repeated byte/i);
    expect(message).not.toContain(value);
  });

  it('a standard-base64 key (with + or /) is refused with a message naming base64url, without printing it', () => {
    const bytes = Buffer.from(Array.from({ length: 32 }, (_, i) => 250 - i));
    const std = bytes.toString('base64');
    expect(std).toMatch(/[+/]/);
    let message = '';
    try {
      keyringFrom(`k:${std}`);
    } catch (e) {
      message = (e as Error).message;
    }
    expect(message).toMatch(/base64url/);
    expect(message).not.toContain(std);
  });

  it('a key with two distinct byte values is accepted', () => {
    const bytes = Buffer.alloc(32, 3);
    bytes[31] = 4;
    expect(keyringFrom(`k:${bytes.toString('base64url')}`)).toHaveLength(1);
  });
});
