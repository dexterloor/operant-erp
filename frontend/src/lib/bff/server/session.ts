import 'server-only';
import { CompactEncrypt, compactDecrypt } from 'jose';

// The sealed session cookie (bff-auth-note.md §2 + R1-§2 + R1-§2a L2; b3b-design.md §5 items 1–3, 15; §3 R-TK).
// JWE `dir` / `A256GCM` under BFF_SESSION_KEY, with a key id so a second key can be added for rotation. The payload is
// {v, sid, jwt, exp, workspace_typed, tz} from this commit on (L-10): `v` is the key id that sealed it, `exp` the
// token's own `expires_at` in epoch seconds (the hard ceiling; the BFF never extends it), `tz` the viewer's zone as
// reported at sign-in (or UTC).
//
// The token never leaves the server: an opened Session carries no `jwt` member. It is reachable only through tokenOf(),
// and clientSessionView() is the one shape that may be passed to client components (guards G-6 and T-TK).

export const SESSION_COOKIE = '__Host-erp_session';

export interface SessionPayload {
  readonly v: string;
  readonly sid: string;
  readonly jwt: string;
  readonly exp: number;
  readonly workspace_typed: string;
  readonly tz: string;
}

/** An opened session. Deliberately without the token: see tokenOf(). */
export interface Session {
  readonly sid: string;
  readonly exp: number;
  readonly workspaceTyped: string;
  readonly tz: string;
}

/** What a client component may receive from the session, and nothing else. */
export interface ClientSessionView {
  readonly sid: string;
  readonly tz: string;
}

interface Key {
  readonly id: string;
  readonly bytes: Uint8Array;
}
/** Newest key first. Sealing uses the first; opening accepts any. */
export type Keyring = readonly Key[];

const TOKENS = new WeakMap<Session, string>();

/** The session's bearer token. Server-only; never pass the result to a component. */
export function tokenOf(session: Session): string {
  const t = TOKENS.get(session);
  if (t === undefined) throw new Error('Not an opened session.');
  return t;
}

export function clientSessionView(session: Session): ClientSessionView {
  return { sid: session.sid, tz: session.tz };
}

/**
 * Parses `BFF_SESSION_KEY`: comma-separated `<key id>:<base64url of 32 random bytes>`, newest first. Referenced by name
 * only; the value is never printed or logged.
 */
export function keyringFrom(raw: string | undefined): Keyring {
  if (typeof raw !== 'string' || raw.trim() === '') throw new Error('The session key is not configured.');
  const ring: Key[] = [];
  for (const entry of raw.split(',')) {
    const at = entry.indexOf(':');
    const id = entry.slice(0, at).trim();
    const b64 = entry.slice(at + 1).trim();
    if (at <= 0 || !/^[A-Za-z0-9_-]+$/.test(id) || !/^[A-Za-z0-9_-]+={0,2}$/.test(b64)) throw new Error('The session key is malformed.');
    const bytes = new Uint8Array(Buffer.from(b64, 'base64url'));
    if (bytes.length !== 32) throw new Error('The session key must be 32 bytes.');
    if (ring.some((k) => k.id === id)) throw new Error('The session key ids must differ.');
    ring.push({ id, bytes });
  }
  return ring;
}

/** The keyring from the environment, read when needed (never at module load). */
export function envKeyring(): Keyring {
  return keyringFrom(process.env.BFF_SESSION_KEY);
}

/** A usable IANA zone of at most 64 characters, else `UTC` (design §5 item 15). */
export function validTimeZone(raw: unknown): string {
  if (typeof raw !== 'string' || raw === '' || raw.length > 64) return 'UTC';
  try {
    new Intl.DateTimeFormat('en-GB', { timeZone: raw });
    return raw;
  } catch {
    return 'UTC';
  }
}

/** A new session's payload, with a fresh `sid` per sign-in (R1-§4). `v` is filled in by sealSession(). */
export function newSessionPayload(args: { jwt: string; exp: number; workspaceTyped: string; timeZone: unknown }): Omit<SessionPayload, 'v'> {
  return { sid: crypto.randomUUID(), jwt: args.jwt, exp: args.exp, workspace_typed: args.workspaceTyped, tz: validTimeZone(args.timeZone) };
}

export async function sealSession(payload: Omit<SessionPayload, 'v'>, ring: Keyring = envKeyring()): Promise<string> {
  const key = ring[0];
  if (!key) throw new Error('The session key is not configured.');
  const body: SessionPayload = { v: key.id, sid: payload.sid, jwt: payload.jwt, exp: payload.exp, workspace_typed: payload.workspace_typed, tz: payload.tz };
  return new CompactEncrypt(new TextEncoder().encode(JSON.stringify(body))).setProtectedHeader({ alg: 'dir', enc: 'A256GCM', kid: key.id }).encrypt(key.bytes);
}

const isString = (x: unknown): x is string => typeof x === 'string';

/**
 * The opened session, or null for anything that is not a live session: no value, a value that does not open under any
 * listed key, a malformed payload, or a sealed `exp` that has passed (`nowSeconds` ≥ exp). Never throws on input.
 */
export async function unsealSession(value: string | undefined | null, ring: Keyring = envKeyring(), nowSeconds = Date.now() / 1000): Promise<Session | null> {
  if (!isString(value) || value === '' || value.length > 8192) return null;
  let parsed: unknown;
  try {
    const { plaintext, protectedHeader } = await compactDecrypt(
      value,
      (header) => {
        const key = ring.find((k) => k.id === header.kid);
        if (!key) throw new Error('unknown key');
        return key.bytes;
      },
      { keyManagementAlgorithms: ['dir'], contentEncryptionAlgorithms: ['A256GCM'] },
    );
    parsed = JSON.parse(new TextDecoder().decode(plaintext));
    if (!parsed || typeof parsed !== 'object' || (parsed as { v?: unknown }).v !== protectedHeader.kid) return null;
  } catch {
    return null;
  }
  const p = parsed as Record<string, unknown>;
  if (!isString(p.sid) || !isString(p.jwt) || typeof p.exp !== 'number' || !isString(p.workspace_typed) || !isString(p.tz)) return null;
  if (!(nowSeconds < p.exp)) return null;
  const session: Session = Object.freeze({ sid: p.sid, exp: p.exp, workspaceTyped: p.workspace_typed, tz: validTimeZone(p.tz) });
  TOKENS.set(session, p.jwt);
  return session;
}

/** For server components: the session behind the cookie value, under the environment's keys and clock. */
export function readSession(cookieValue: string | undefined): Promise<Session | null> {
  return unsealSession(cookieValue);
}

const ATTRIBUTES = 'Path=/; Secure; HttpOnly; SameSite=Lax';

/** L2: a session cookie (no Expires, no Max-Age); the sealed `exp` ends it. `__Host-` forbids a Domain. */
export function sessionCookie(value: string): string {
  return `${SESSION_COOKIE}=${value}; ${ATTRIBUTES}`;
}

export function clearedSessionCookie(): string {
  return `${SESSION_COOKIE}=; ${ATTRIBUTES}; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT`;
}
