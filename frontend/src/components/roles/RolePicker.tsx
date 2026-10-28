'use client';

import { useId, useLayoutEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import type { Permission } from '@/lib/api/permissions';
import type { OfferedRole, Role } from '@/lib/api/roles';
import type { User } from '@/lib/api/users';
import { formatList } from '@/lib/format/lists';
import { plural } from '@/lib/format/plural';
import { diff, isUnavailable, saveBlocked, unavailableIds } from '@/lib/roles/availability';
import { conflictLinesOf } from '@/lib/roles/conflictLines';
import { previewOf } from '@/lib/roles/effectivePreview';
import { afterSetOf, giveKindOf, reconcile, toggle, without, type Intents } from '@/lib/roles/intents';
import { marginKey, marginWarningOf } from '@/lib/roles/margin';
import type { RereadResult, RolePickerActions } from '@/lib/roles/results';
import { isSuccess, planSave, runSave, type Outcome, type SaveRun } from '@/lib/roles/save';
import { isRefusal, saveSummary, tickStatus } from '@/lib/roles/statusLine';
import { PICKER, PICKER_BANNERS, PICKER_STATUS, ROLES_FORBIDDEN } from '@/copy/roles';
import { COULD_NOT_REACH, notGiven, notProposed, notTakenAway, REFUSED_WITHOUT_MESSAGE, WENT_WRONG } from '@/copy/headings';
import { PAGE_STATES } from '@/copy/shell';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { LiveRegion, useLiveRegion } from '@/components/ui/LiveRegion';
import { EffectivePermissions, marginSentences } from './EffectivePermissions';
import { RoleOption, type OutcomeChip } from './RoleOption';
import styles from './RolePicker.module.css';

export interface RolePickerProps {
  /** `GetUser`: held roles are its `roles[]` (never `refusal`); `margin_exposure` is the held form's only source. */
  person: User;
  /** The served `GetCurrentUser` id: equal to `person.id` on your own page (every control aria-disabled). */
  viewerId: string;
  /** `ListOfferedRoles` items, as served (`role.assign`). */
  offered: readonly OfferedRole[];
  /**
   * Revision 3 A-1: `role.id` of every pending proposal with `is_expired` false from the WHOLE
   * `ListRoleGrantProposals?user={id}&status=pending` walk the Waiting group makes — never derived from the visible page.
   */
  pendingRoleIds: ReadonlySet<string>;
  /** `ListRoles` + `ListPermissions`, both only with `role.view` (RED M-10). Absent: permissions aren't shown. */
  roles?: readonly Role[];
  permissions?: readonly Permission[];
  actions: RolePickerActions;
}

type Intended = { personId: string; intents: Intents };
type Chips = { personId: string; byRole: ReadonlyMap<string, Exclude<OutcomeChip, null>> };
interface Post {
  gen: number;
  run: SaveRun;
  reread: RereadResult;
  /** `person.margin_exposure` when Save was pressed (4b-2: announce the held form only when the re-read made it appear). */
  marginBefore: boolean;
  /** Load roles again succeeded after an interrupted save (R1-2): re-announce case B / show case A. */
  reloaded: boolean;
  /**
   * b4-final-red M-2: the roles THIS save left undecided (the interrupted request and the ones not sent after it),
   * frozen when the save answers. R1-2's *n* is "the intents still staged after the reconcile" of that save, so a tick
   * made later is never counted as a change the save stopped on.
   */
  undecidedIds: ReadonlySet<string>;
}
interface PickerBanner {
  key: string;
  heading: string;
  body: ReactNode;
  loadAgain?: boolean;
}

const EMPTY: Intents = new Map();
const interruptedHeading = (kind: 'unreachable' | 'server-error') => (kind === 'unreachable' ? COULD_NOT_REACH : WENT_WRONG);
const isInterrupt = (stop: SaveRun['stop']): stop is 'unreachable' | 'server-error' => stop === 'unreachable' || stop === 'server-error';
const UNDECIDED: ReadonlySet<Outcome['result']['kind']> = new Set(['unreachable', 'server-error', 'not-sent']);
/** The save's undecided roles that are still staged now (M-2): current intents ∩ the frozen set. */
const stillMarkedOf = (post: Post, intents: Intents) => [...intents.keys()].filter((id) => post.undecidedIds.has(id));

/**
 * The role picker (§5.14; round-6 §2a), props only. Staging is an intents map reconciled against the props in render
 * (RED H-1, Revision 3 A-1); availability is `lib/roles/availability.ts`'s one SoD derivation (principle 2 (a)); Save
 * roles runs `lib/roles/save.ts` (take-aways → gives → proposals, sequential; A-2, A-4) and then ALWAYS calls `reread()`.
 * **Save roles is never re-mounted** (4b-1): its node survives every state, so focus can stay on it.
 * Not rendered for a deleted person (the page's own banner explains); an inactive person gets one banner instead.
 * `RoleProposals` is a SIBLING the page composes (RED M-3), not a child.
 */
export function RolePicker({ person, viewerId, offered, pendingRoleIds, roles, permissions, actions }: RolePickerProps) {
  const [intended, setIntended] = useState<Intended>({ personId: person.id, intents: EMPTY });
  const [chips, setChips] = useState<Chips>({ personId: person.id, byRole: new Map() });
  const [busy, setBusy] = useState(false);
  const [reloading, setReloading] = useState(false);
  const [post, setPost] = useState<Post | null>(null);
  const status = useLiveRegion();
  const saveRef = useRef<HTMLButtonElement>(null);
  const firstBannerRef = useRef<HTMLParagraphElement>(null);

  const heldIds = useMemo(() => new Set(person.roles.map((r) => r.id)), [person.roles]);
  const ownPage = viewerId === person.id;
  const facts = useMemo(() => ({ heldIds, offered, pendingRoleIds }), [heldIds, offered, pendingRoleIds]);
  // Derived reset (RED M-8) for another person, then the reconcile against these props (H-1, A-1).
  const intents = reconcile(intended.personId === person.id ? intended.intents : EMPTY, facts);
  const byRole = chips.personId === person.id ? chips.byRole : new Map<string, Exclude<OutcomeChip, null>>();
  const afterSet = afterSetOf(heldIds, intents);
  const avail = { offered, heldIds, afterSet, ownPage };
  const blocked = saveBlocked(avail);
  const warning = marginWarningOf(person.margin_exposure, offered, intents, afterSet);
  const preview = roles && permissions ? previewOf(roles, heldIds, intents, offered) : null;
  const nameOf = (roleId: string) => offered.find((r) => r.id === roleId)?.name ?? person.roles.find((r) => r.id === roleId)?.name ?? roleId;

  const uid = useId();
  const ownLineId = `${uid}-own`;
  const saveReasonId = `${uid}-save-reason`;

  // ---- ticks ---------------------------------------------------------------------------------------------------
  const onToggle = (roleId: string, next: boolean) => {
    if (busy || ownPage) return;
    const role = offered.find((r) => r.id === roleId);
    if (!role) return;
    if (!heldIds.has(roleId) && next && isUnavailable(role, avail)) return;
    const nextIntents = toggle(intents, roleId, next, heldIds);
    const nextAfter = afterSetOf(heldIds, nextIntents);
    const beforeIds = unavailableIds(avail);
    const afterIds = unavailableIds({ ...avail, afterSet: nextAfter });
    const d = diff(offered, beforeIds, afterIds);
    const others = (list: string[]) => list.filter((id) => id !== roleId).map(nameOf);
    const nextWarning = marginWarningOf(person.margin_exposure, offered, nextIntents, nextAfter);
    const changedMargin = marginKey(nextWarning) !== marginKey(warning) ? marginSentences(nextWarning) : [];
    // ux-confirm-b4.md §2.7 (M-3): this toggle made Save roles unavailable because nothing is staged any more.
    const nothingStaged = intents.size > 0 && nextIntents.size === 0 && !saveBlocked({ ...avail, afterSet: nextAfter });
    setIntended({ personId: person.id, intents: nextIntents });
    setChips({ personId: person.id, byRole: new Map() });
    // ux-confirm-b4.md §2.8 (L-D3): a row the person has changed leaves the interrupted save's frozen set.
    if (post?.undecidedIds.has(roleId)) {
      const rest = new Set(post.undecidedIds);
      rest.delete(roleId);
      setPost({ ...post, undecidedIds: rest });
    }
    status.announce(
      tickStatus(PICKER_STATUS, formatList, {
        newlyUnavailable: others(d.newlyUnavailable),
        availableAgain: others(d.availableAgain),
        cause: role.name,
        marginSentences: changedMargin,
        nothingStaged,
      }),
    );
  };

  // ---- Save roles ----------------------------------------------------------------------------------------------
  const onSave = async () => {
    if (busy || ownPage || blocked || intents.size === 0) return;
    const plan = planSave(intents, offered, nameOf);
    const marginBefore = person.margin_exposure;
    const personId = person.id;
    setBusy(true);
    const run = await runSave(plan, actions, (o: Outcome) => {
      if (!isSuccess(o.result)) return;
      // A-2: a 2xx clears its own intent at once, before the re-read; Given / Taken away chips from the same answer.
      setIntended((cur) => (cur.personId === personId ? { personId, intents: without(cur.intents, o.request.roleId) } : cur));
      if (o.result.kind === 'given' || o.result.kind === 'taken-away') {
        const chip = o.result.kind;
        setChips((cur) => {
          const m = new Map(cur.personId === personId ? cur.byRole : []);
          m.set(o.request.roleId, chip);
          return { personId, byRole: m };
        });
      }
    });
    let reread: RereadResult;
    try {
      reread = await actions.reread();
    } catch {
      reread = { kind: 'failed', failure: 'unreachable' };
    }
    setBusy(false);
    const undecidedIds = new Set(run.outcomes.filter((o) => UNDECIDED.has(o.result.kind)).map((o) => o.request.roleId));
    setPost((cur) => ({ gen: (cur?.gen ?? 0) + 1, run, reread, marginBefore, reloaded: false, undecidedIds }));
  };

  const onLoadAgain = async () => {
    if (reloading || !post) return;
    setReloading(true);
    let reread: RereadResult;
    try {
      reread = await actions.reread();
    } catch {
      reread = { kind: 'failed', failure: 'unreachable' };
    }
    setReloading(false);
    // On failure the banner is unchanged and focus stays on the button (R1-2).
    if (reread.kind === 'ok') setPost({ ...post, gen: post.gen + 1, reread, reloaded: true });
  };

  // ---- banners after a save (R1-2, R1-5, R1-6), derived from the run and the CURRENT props ------------------------
  const banners: PickerBanner[] = [];
  if (post && post.run.stop !== 'session-ended') {
    const { run, reread } = post;
    const stillMarked = stillMarkedOf(post, intents).length;
    const lines = (kinds: Array<[string, (roles: string) => string]>) =>
      kinds
        .map(([kind, word]) => [run.outcomes.filter((o) => o.result.kind === kind).map((o) => o.request.roleName), word] as const)
        .filter(([n]) => n.length > 0)
        .map(([n, word]) => word(formatList(n)));
    const successLines = lines([
      ['given', PICKER_STATUS.given],
      ['proposed', PICKER_STATUS.proposed],
      ['taken-away', PICKER_STATUS.takenAway],
    ]);
    const actionOf = (o: Outcome) =>
      o.request.kind === 'give' ? PICKER_BANNERS.giving(o.request.roleName) : o.request.kind === 'propose' ? PICKER_BANNERS.proposing(o.request.roleName) : PICKER_BANNERS.takingAway(o.request.roleName);

    if (run.stop === 'forbidden') {
      // A-4 + R1-5: one banner; n = the 403 plus the rows not sent after it. Never R1-2's banner.
      const n = run.outcomes.filter((o) => o.result.kind === 'forbidden' || o.result.kind === 'not-sent').length;
      banners.push({
        key: 'forbidden',
        heading: plural(n, PICKER_BANNERS.notSaved),
        body: (
          <p>
            {ROLES_FORBIDDEN.before}
            <em>{ROLES_FORBIDDEN.label}</em>
            {ROLES_FORBIDDEN.after}
            {/* Gaps Rev 2 item 1 (P-5): the re-read failed too — one appended sentence, no button (the viewer can't save). */}
            {reread.kind === 'failed' ? ` ${PICKER_BANNERS.marksMayBeStale}` : null}
          </p>
        ),
      });
    } else if (isInterrupt(run.stop) && reread.kind === 'ok') {
      // R1-2 case A (A-1 + A-2 adopted): heading `Not saved: {n} changes`, the R1-1 heading text as the first sentence.
      if (stillMarked > 0) {
        banners.push({
          key: 'interrupted',
          heading: plural(stillMarked, PICKER_BANNERS.notSaved),
          body: <p>{`${interruptedHeading(run.stop)} ${plural(stillMarked, PICKER_BANNERS.stillMarked)}`}</p>,
        });
      }
    } else if (reread.kind === 'failed' && !isInterrupt(run.stop) && run.outcomes.some(isRefusal)) {
      // Case E (b4-step5-gaps.md Rev 1 P-1 + Rev 2 items 3–4): refusals, no transport failure, the re-read failed. Its own
      // lead (never case C's), the 2xx lines, no "can't tell" / "Not sent" lines; the refused rows' banners follow.
      banners.push({
        key: 'interrupted',
        heading: interruptedHeading(reread.failure),
        loadAgain: true,
        body: (
          <>
            <p>{PICKER_BANNERS.marksMayBeStale}</p>
            {successLines.map((l) => (
              <p key={l}>{l}</p>
            ))}
            <p>{PICKER_BANNERS.loadFirst}</p>
          </>
        ),
      });
    } else if (reread.kind === 'failed' && isInterrupt(run.stop)) {
      // R1-2 case C.
      const stopped = run.outcomes.find((o) => o.result.kind === 'unreachable' || o.result.kind === 'server-error');
      const notSent = run.outcomes.filter((o) => o.result.kind === 'not-sent').map(actionOf);
      banners.push({
        key: 'interrupted',
        heading: interruptedHeading(run.stop),
        loadAgain: true,
        body: (
          <>
            <p>{PICKER_BANNERS.rereadFailedLead}</p>
            {successLines.map((l) => (
              <p key={l}>{l}</p>
            ))}
            {stopped ? <p>{PICKER_BANNERS.cantTell(actionOf(stopped))}</p> : null}
            {notSent.length > 0 ? <p>{PICKER_BANNERS.notSent(formatList(notSent))}</p> : null}
            <p>{PICKER_BANNERS.loadFirst}</p>
          </>
        ),
      });
    } else if (reread.kind === 'failed') {
      // R1-2 case D with A-2: every request 2xx, the re-read failed.
      banners.push({ key: 'interrupted', heading: interruptedHeading(reread.failure), loadAgain: true, body: <p>{PICKER_BANNERS.savedButStale}</p> });
    }
    for (const o of run.outcomes) {
      if (o.result.kind !== 'refused' && o.result.kind !== 'not-found') continue;
      const heading = o.request.kind === 'give' ? notGiven(o.request.roleName) : o.request.kind === 'propose' ? notProposed(o.request.roleName) : notTakenAway(o.request.roleName);
      const body =
        o.result.kind === 'refused'
          ? o.result.message !== ''
            ? o.result.message
            : REFUSED_WITHOUT_MESSAGE
          : o.request.kind === 'take-away'
            ? PICKER_BANNERS.nothingToTakeAway
            : `${PAGE_STATES.notFoundHeading} ${PAGE_STATES.notFoundBody}`;
      banners.push({ key: `refused-${o.request.roleId}`, heading, body: <p>{body}</p> });
    }
  }

  // ---- focus, then the summary (4b-1; R1-1): in the commit that shows the banners, never a task later ---------------
  const postGen = post?.gen ?? null;
  useLayoutEffect(() => {
    if (postGen === null || !post) return;
    if (post.run.stop === 'session-ended') return; // R1-4: the fetch wrapper navigates; nothing in-page.
    if (firstBannerRef.current) firstBannerRef.current.focus();
    else saveRef.current?.focus();
    const interrupted = isInterrupt(post.run.stop);
    // Gaps Rev 1 P-3: Load roles again succeeded after a save that was not interrupted (case D/E) — focus as built,
    // then this one sentence.
    if (post.reloaded && !interrupted) {
      status.announce(PICKER_STATUS.reloaded);
      return;
    }
    const nothingStillMarked = post.reread.kind === 'ok' && stillMarkedOf(post, intents).length === 0;
    const heldForm = post.reread.kind === 'ok' && !post.marginBefore && person.margin_exposure ? PICKER.marginHeld : null;
    status.announce(saveSummary(PICKER_STATUS, formatList, { outcomes: post.run.outcomes, interrupted, nothingStillMarked, heldForm }));
    // Runs once per save / reload generation only; the values read are this commit's.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [postGen]);

  if (person.archived_at !== null) return null;
  if (!person.is_active) {
    return (
      <div className={styles.picker}>
        <Banner tone="info" live="none" heading={PICKER.inactiveBanner} />
      </div>
    );
  }

  const stagedCount = intents.size;
  // Precedence (ux-confirm-b4.md §2.7): own page > conflict > busy (no reason) > nothing staged.
  const nothingToSave = stagedCount === 0 && !busy && !ownPage && !blocked;
  const saveReasonIds = [ownPage ? ownLineId : null, !ownPage && (blocked || nothingToSave) ? saveReasonId : null].filter(Boolean).join(' ');

  return (
    <div className={styles.picker}>
      {banners.length > 0 ? (
        <div className={styles.banners}>
          {banners.map((b, i) => (
            <Banner
              key={b.key}
              tone="danger"
              live="none"
              heading={b.heading}
              ref={i === 0 ? firstBannerRef : undefined}
              action={
                b.loadAgain ? (
                  <Button loading={reloading} onClick={() => void onLoadAgain()}>
                    {PICKER_BANNERS.loadAgain}
                  </Button>
                ) : undefined
              }
            >
              {b.body}
            </Banner>
          ))}
        </div>
      ) : null}
      <div className={styles.columns}>
        <section aria-labelledby={`${uid}-heading`} className={styles.rolesSection} data-surface="role-picker">
          <h2 id={`${uid}-heading`} className={styles.panelHeading}>
            {PICKER.rolesHeading}
          </h2>
          {ownPage ? (
            <p id={ownLineId} className={styles.reason}>
              {PICKER.ownPage}
            </p>
          ) : null}
          <ul className={styles.options}>
            {offered.map((role) => {
              const held = heldIds.has(role.id);
              const intent = intents.get(role.id);
              return (
                <RoleOption
                  key={role.id}
                  idPrefix={uid}
                  role={role}
                  held={held}
                  checked={afterSet.has(role.id)}
                  unavailable={isUnavailable(role, avail)}
                  staged={intent === 'take-away' ? 'take-away' : intent === 'give' ? giveKindOf(role) : null}
                  outcome={byRole.get(role.id) ?? null}
                  conflicts={conflictLinesOf(role, heldIds, afterSet)}
                  pageReasonIds={ownPage ? ownLineId : undefined}
                  onToggle={onToggle}
                />
              );
            })}
          </ul>
          <div className={styles.saveRow}>
            {stagedCount > 0 ? <span className={styles.changeCount}>{plural(stagedCount, PICKER.changes)}</span> : null}
            <Button ref={saveRef} variant="primary" loading={busy} unavailableReasonId={saveReasonIds || undefined} onClick={() => void onSave()}>
              {PICKER.save}
            </Button>
            {!ownPage && (blocked || nothingToSave) ? (
              <p id={saveReasonId} className={styles.reason}>
                {blocked ? PICKER.saveBlocked : PICKER.saveNothing}
              </p>
            ) : null}
          </div>
          <LiveRegion message={status.message} className={styles.status} />
        </section>
        <EffectivePermissions roles={roles} permissions={permissions} preview={preview} warning={warning} />
      </div>
    </div>
  );
}
