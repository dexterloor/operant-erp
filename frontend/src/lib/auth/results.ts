// Typed results of the three credential actions. The pages take the action as an injected async callback and render
// whatever it resolves to; B-3b maps BFF responses onto these unions. No fetch, no route handler, no cookie code here
// (ruling item 9: props only).
//
// `message` values are the SERVER's 422 sentences, rendered verbatim on their field (ST-3, review L-6). 401 and 429 on
// the reset endpoints carry no human `detail`, so those variants carry no text: the page supplies the client copy
// (ux-copy-frontend.md §1.4, the one named exception).

// Unreachable, ServerError and RateLimited now live once in `lib/results.ts` (B-4 step 1, Revision 2 C-3); they are
// re-exported here so every B-3a import is unchanged.
import type { RateLimited, ServerError, Unreachable } from '../results';
export type { RateLimited, ServerError, Unreachable } from '../results';

/**
 * The web app's server refused the request before sending it to the API (its origin, size, time or shape checks), so
 * nothing changed and the outcome is known (c10 FINAL A.2). Never "we can't tell": that is for `unreachable` and the
 * BFF's generic server error, where the API may have acted.
 */
export interface NotSent {
  kind: 'not-sent';
}

/** Field pointer → the server's sentence for it. */
export type FieldErrors<F extends string> = Partial<Record<F, string>>;

/** `POST /auth/password-resets` `{workspace, email}`. */
export type RequestResetResult =
  /** 202, for every well-formed input. Says nothing about whether mail will be sent. */
  | { kind: 'accepted' }
  /** 422 `/workspace`, `/email`. */
  | { kind: 'invalid'; fieldErrors: FieldErrors<'workspace' | 'email'> }
  | NotSent
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
  | NotSent
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

/** `POST /bff/session` (b3b-design.md §5 item 5): the three fields, plus two the BFF reads and never forwards. */
export interface SignInRequest {
  workspace: string;
  email: string;
  password: string;
  /** `?return_to` as the page received it; the BFF validates it (SD-1). */
  returnTo: string | null;
  /** The browser's IANA zone; the BFF seals it, or UTC (design §5 item 15). */
  timeZone: string;
}

/** The sign-in outcomes. Only `signed-in` navigates, and only to the server's `redirect`. A 401 here is never "session ended". */
export type SignInResult =
  | { kind: 'signed-in'; redirect: string }
  /** 401 `authentication-failed`, every cause (ST-7); also a 422 shape refusal. */
  | { kind: 'failure' }
  /** 429 `too-many-attempts` (ST-8a). */
  | RateLimited
  /** 429 `sign-in-paused` (ST-8b). */
  | { kind: 'paused' }
  | Unreachable
  | ServerError;

export type SignInAction = (request: SignInRequest) => Promise<SignInResult>;
