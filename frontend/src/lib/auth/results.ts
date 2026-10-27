// Typed results of the three credential actions. The pages take the action as an injected async callback and render
// whatever it resolves to; B-3b maps BFF responses onto these unions. No fetch, no route handler, no cookie code here
// (ruling item 9: props only).
//
// `message` values are the SERVER's 422 sentences, rendered verbatim on their field (ST-3, review L-6). 401 and 429 on
// the reset endpoints carry no human `detail`, so those variants carry no text: the page supplies the client copy
// (ux-copy-frontend.md §1.4, the one named exception).

/** Field pointer → the server's sentence for it. */
export type FieldErrors<F extends string> = Partial<Record<F, string>>;

/** A failure that never reached a usable response (fetch rejected, offline). */
export interface Unreachable {
  kind: 'unreachable';
}
/** 5xx or an unexpected status. Never carries the server's `detail` (ST-3: 5xx detail is for the log). */
export interface ServerError {
  kind: 'server-error';
}
/** 429. Sign-in's budgets (ST-8a); no count, no window. */
export interface RateLimited {
  kind: 'rate-limited';
}

/** `POST /auth/password-resets` `{workspace, email}`. */
export type RequestResetResult =
  /** 202, for every well-formed input. Says nothing about whether mail will be sent. */
  | { kind: 'accepted' }
  /** 422 `/workspace`, `/email`. */
  | { kind: 'invalid'; fieldErrors: FieldErrors<'workspace' | 'email'> }
  | RateLimited
  | Unreachable
  | ServerError;

export interface RequestResetValues {
  workspace: string;
  email: string;
}

/** `POST /auth/password-resets/redemption` `{token, password}`. */
export type RedeemResetResult =
  /**
   * 200 `{changed_at}`. `receivedAt` is `performance.now()` at the moment the response arrived, if the caller measured
   * it; otherwise the page takes `performance.now()` when the action resolves (DT-6: the wait is measured from arrival,
   * never by comparing `changedAt` with the browser clock).
   */
  | { kind: 'changed'; changedAt: string; receivedAt?: number }
  /** 422 `/password` — only on a good link; the link is NOT used up. */
  | { kind: 'invalid'; fieldErrors: FieldErrors<'password'> }
  /** 401 — one state for every cause; never the shell's "session ended". */
  | { kind: 'link-refused' }
  | RateLimited
  | Unreachable
  | ServerError;

/** `POST /me/password` `{current_password, new_password}` — owner ruling P-A: success signs out everywhere. */
export type ChangePasswordResult =
  | { kind: 'changed'; receivedAt?: number }
  /** 422 `/current_password`, `/new_password`. */
  | { kind: 'invalid'; fieldErrors: FieldErrors<'current_password' | 'new_password'> }
  /** 401: the session has ended (BFF note §8 "Change password"); the caller routes to sign-in. */
  | { kind: 'session-ended' }
  | RateLimited
  | Unreachable
  | ServerError;

export interface ChangePasswordValues {
  current_password: string;
  new_password: string;
}

export type RequestResetAction = (values: RequestResetValues) => Promise<RequestResetResult>;
/** The token is passed as an argument and never stored by the page outside component memory. */
export type RedeemResetAction = (values: { token: string; password: string }) => Promise<RedeemResetResult>;
export type ChangePasswordAction = (values: ChangePasswordValues) => Promise<ChangePasswordResult>;

/** Full document load (BFF note R2-4: never `router.push` after a credential change). Injectable for tests. */
export type DocumentNavigate = (url: string) => void;
export const assignLocation: DocumentNavigate = (url) => {
  window.location.assign(url);
};
