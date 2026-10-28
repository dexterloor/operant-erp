// The loopback rule for the API base URL (bff-auth-note.md R1-§10; b3b-design.md §5 items 17–18, ruling Q-7). Pure and
// framework-free, so the build-time half (next.config.ts) and the run-time half (lib/bff/server/apiClient.ts) share one
// copy of it. Exact host equality: `localhost.evil.example` and `127.0.0.1.nip.io` are not loopback.

const LOOPBACK_HOSTS: ReadonlySet<string> = new Set(['127.0.0.1', '[::1]', 'localhost']);

/** True when `raw` parses as an http(s) URL whose host is exactly a loopback name, with no credentials, path or query. */
export function isLoopbackBase(raw: string | undefined | null): boolean {
  if (typeof raw !== 'string' || raw === '') return false;
  let url: URL;
  try {
    url = new URL(raw);
  } catch {
    return false;
  }
  if (url.protocol !== 'http:' && url.protocol !== 'https:') return false;
  if (url.username !== '' || url.password !== '' || url.search !== '' || url.hash !== '') return false;
  if (url.pathname !== '/' && url.pathname !== '') return false;
  return LOOPBACK_HOSTS.has(url.hostname);
}

/**
 * The same exact-host rule for the web app's own public origin (code RED M-3). Until the deployment commit, the app may
 * only be served from this machine: a self-hosted deployment beside the API (`next start` on a VPS) would send every
 * person's sign-in from the loopback address and share one pre-auth budget, so both guard halves refuse it.
 */
export function isLoopbackOrigin(raw: string | undefined | null): boolean {
  return isLoopbackBase(raw);
}
