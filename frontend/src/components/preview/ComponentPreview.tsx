'use client';

import { useCallback, useMemo, useRef, useState } from 'react';
import { Archive, Download } from 'lucide-react';
import { NAVIGATION } from '@/lib/navigation';
import { AppFrame } from '@/components/shell/AppFrame';
import { useThemePreference } from '@/components/theme/useThemePreference';
import { Button } from '@/components/ui/Button';
import { Checkbox } from '@/components/ui/Checkbox';
import { Chip, type ChipTone } from '@/components/ui/Chip';
import { DateTime } from '@/components/ui/DateTime';
import { Dialog } from '@/components/ui/Dialog';
import { LiveRegion, useLiveRegion } from '@/components/ui/LiveRegion';
import { StatusMessages, type StatusMessage } from '@/components/ui/StatusMessages';
import { PersonLifecycleActions } from '@/components/people/PersonLifecycleActions';
import { BulkBar } from '@/components/bulk/BulkBar';
import { BulkHeaderCheckbox } from '@/components/bulk/BulkHeaderCheckbox';
import { BulkNotices, BulkResultMessage } from '@/components/bulk/BulkResultMessage';
import { BulkRowCheckbox } from '@/components/bulk/BulkRowCheckbox';
import { useBulkSelection } from '@/components/bulk/useBulkSelection';
import type { BulkResult } from '@/lib/bulk/results';
import { BULK_PREVIEW_ANSWERS, SUPPLIER_ARCHIVED, SUPPLIER_NOUNS, SUPPLIER_PAGES } from './fixtures/bulk';
import { PERSON } from '@/copy/people';
import type { User } from '@/lib/api/users';
import type { DeletePersonResult, RestorePersonResult } from '@/lib/people/results';
import { PermissionChecklist } from '@/components/permissions/PermissionChecklist';
import type { Role } from '@/lib/api/roles';
import type { SaveRoleResult } from '@/lib/permissions/results';
import {
  CUSTOM_ROLE,
  MARGIN_ROLE,
  OWNER_ROLE,
  PERMISSIONS,
  PURCHASING_MANAGER_ROLE,
  SAVE_REFUSALS,
  UNLABELLED_PERMISSION,
} from './fixtures/permissions';
import {
  DELETE_REFUSALS,
  FIXTURE_ONLY,
  PERSON_ACTIVE,
  PERSON_DELETED_ACTIVE,
  PERSON_DELETED_SWITCHED_OFF,
  PERSON_SELF,
  RESTORE_REFUSALS,
  VIEWER_ID,
} from './fixtures/people';
import { RolePicker } from '@/components/roles/RolePicker';
import { RoleProposals } from '@/components/roles/RoleProposals';
import type { RoleGrantProposal } from '@/lib/api/proposals';
import type { OfferedRole } from '@/lib/api/roles';
import type { RoleActionFailure } from '@/lib/roles/results';
import {
  HELD_PAIR_PERSON,
  JONAS,
  OFFERED_HELD_PAIR_BY_ADMIN,
  OFFERED_JONAS_BY_ADMIN,
  OFFERED_JONAS_BY_OWNER,
  OFFERED_SELF_ADMIN,
  OWNER_VIEWER_ID,
  PROPOSAL_BY_OTHER,
  PROPOSAL_EXPIRED,
  PROPOSAL_FINANCE,
  PROPOSAL_REFUSALS,
  ROLES,
  SAVE_REFUSALS as PICKER_REFUSALS,
  SELF_ADMIN,
  page,
} from './fixtures/roles';
import { SignInCard, type SignInNotice } from '@/components/auth/SignInCard';
import { NotFound, OfflineBanner, PermissionDenied, Refetchable, RegionError, RegionLoading, Skeleton } from '@/components/states/PageStates';
import styles from './ComponentPreview.module.css';

// Neutral placeholders only — no business sample data (G-37: never the export's DKK/NL/JP samples).
const PLACEHOLDER_USER = { displayName: 'Sample Person', email: 'person@example.com' };
const PLACEHOLDER_WORKSPACE = { displayName: 'Sample Workspace', signInName: 'sample-workspace' };
const ALL_NAV_CODES = NAVIGATION.flatMap((g) => g.items.map((i) => i.permission));

/**
 * COMPONENT PREVIEW — NOT A SCREEN. Renders the B-3a components with illustrative props so they can be checked by eye,
 * by keyboard and by axe. The permission checkboxes drive the sidebar exactly as GetCurrentUser's codes will in B-3b.
 */
export function ComponentPreview() {
  const [theme, setTheme] = useThemePreference();
  const [codes, setCodes] = useState<Set<string>>(() => new Set(ALL_NAV_CODES));
  const [messages, setMessages] = useState<StatusMessage[]>([]);
  const [busy, setBusy] = useState(false);
  const [loading, setLoading] = useState(false);
  const [counter, setCounter] = useState(0);

  const dismiss = useCallback((id: string) => setMessages((m) => m.filter((x) => x.id !== id)), []);
  const toggle = (code: string) =>
    setCodes((prev) => {
      const next = new Set(prev);
      if (next.has(code)) next.delete(code);
      else next.add(code);
      return next;
    });

  const sorted = useMemo(() => [...codes].sort(), [codes]);
  const signInVariants: { notice: SignInNotice | null; label: string; workspace?: { value: string; source: 'remembered' | 'invitation' } }[] = [
    { notice: null, label: 'Default' },
    { notice: null, label: 'Workspace remembered', workspace: { value: 'sample-workspace', source: 'remembered' } },
    { notice: null, label: 'From an invitation link', workspace: { value: 'sample-workspace', source: 'invitation' } },
    { notice: 'failure', label: 'Failure · every cause' },
    { notice: 'rate-limited', label: 'IP rate limit' },
    { notice: 'paused', label: 'Account paused' },
    { notice: 'session-ended', label: 'Session ended' },
  ];

  return (
    <AppFrame
      permissionCodes={codes}
      currentPath="/component-preview"
      user={PLACEHOLDER_USER}
      workspace={PLACEHOLDER_WORKSPACE}
      theme={theme}
      onThemeChange={setTheme}
      onChangePassword={() => setMessages((m) => [...m, { id: `cp-${Date.now()}`, text: 'Preview: Change password was chosen (wired in B-3b).' }])}
      onSignOut={() => setMessages((m) => [...m, { id: `so-${Date.now()}`, text: 'Preview: Sign out was chosen (wired in B-3b).' }])}
      overlays={<StatusMessages messages={messages} onDismiss={dismiss} />}
    >
      <div className={styles.page}>
        <header className={styles.header}>
          <p className={styles.overline}>Component preview — not a screen</p>
          <h1>B-3a shell components</h1>
          <p className={styles.muted}>Illustrative props only. Nothing here calls an API. Resize the window to see the 248 px sidebar (≥ 1280 px), the 64 px rail and the menu button (&lt; 768 px).</p>
        </header>

        <section className={styles.section} aria-labelledby="pv-perm">
          <h2 id="pv-perm">Sidebar from permission codes</h2>
          <p className={styles.muted}>Each sidebar item renders only when its List operation&apos;s code is held; a group with no item is absent.</p>
          <fieldset className={styles.codes}>
            <legend className="visually-hidden">Permission codes held</legend>
            {ALL_NAV_CODES.map((code) => (
              <label key={code} className={styles.code}>
                <input type="checkbox" checked={codes.has(code)} onChange={() => toggle(code)} />
                <span>{code}</span>
              </label>
            ))}
          </fieldset>
          <p className={styles.muted}>Held: {sorted.length ? sorted.join(', ') : 'none'}</p>
        </section>

        <section className={styles.section} aria-labelledby="pv-buttons">
          <h2 id="pv-buttons">Buttons</h2>
          <div className={styles.row}>
            <Button variant="primary">Primary</Button>
            <Button variant="secondary" icon={<Download aria-hidden="true" width={16} height={16} strokeWidth={1.5} />}>
              Secondary
            </Button>
            <Button variant="tertiary">Tertiary</Button>
            <Button variant="destructive">Destructive</Button>
            <Button variant="destructive-secondary" icon={<Archive aria-hidden="true" width={16} height={16} strokeWidth={1.5} />}>
              Destructive secondary
            </Button>
            <Button variant="link">Link button</Button>
          </div>
          <div className={styles.row}>
            <Button variant="primary" size="sm">
              Small
            </Button>
            <Button variant="primary" size="md">
              Medium
            </Button>
            <Button variant="primary" size="lg">
              Large
            </Button>
            <Button
              variant="primary"
              loading={loading}
              onClick={() => {
                setLoading(true);
                setTimeout(() => setLoading(false), 2000);
              }}
            >
              Commit (loading on click)
            </Button>
            <Button variant="primary" unavailableReason="Preview reason: shown as visible text, tied by aria-describedby.">
              Not available yet
            </Button>
          </div>
        </section>

        <section className={styles.section} aria-labelledby="pv-states">
          <h2 id="pv-states">Page states</h2>
          <div className={styles.frame}>
            <PermissionDenied resourcePlural="purchase orders" permissionName="Purchasing" autoFocus={false} />
          </div>
          <div className={styles.frame}>
            <NotFound listHref="/component-preview" autoFocus={false} />
          </div>
          <RegionError heading="Couldn't load this section." onRetry={() => undefined} autoFocus={false} />
          <OfflineBanner loadedAt="10:42" onRetry={() => undefined} />
          <Skeleton rows={4} label="Loading the preview section" />
          <RegionLoading />
          <div className={styles.row}>
            <Button
              size="sm"
              loading={busy}
              onClick={() => {
                setBusy(true);
                setTimeout(() => {
                  setBusy(false);
                  setCounter((c) => c + 1);
                }, 1500);
              }}
            >
              Refresh (refetching keeps content at full contrast)
            </Button>
          </div>
          <Refetchable busy={busy} announcement={counter ? `Preview refreshed ${counter} time${counter === 1 ? '' : 's'}.` : undefined}>
            <p className={`${styles.refetchContent} erp-highlight`} key={counter}>
              This block stays readable and clickable while it refreshes. Refreshed {counter} time{counter === 1 ? '' : 's'}.
            </p>
          </Refetchable>
        </section>

        <PrimitivesPreview />

        <PeoplePreview />
        <BulkPreview />
        <PermissionsPreview />
        <RolePickerPreview />

        <section className={styles.section} aria-labelledby="pv-messages">
          <h2 id="pv-messages">Status messages</h2>
          <div className={styles.row}>
            <Button onClick={() => setMessages((m) => [...m, { id: `m-${Date.now()}`, text: 'Preview message: this names the effect of an action.' }])}>Add a message (8 s)</Button>
            <Button onClick={() => setMessages((m) => [...m, { id: `p-${Date.now()}`, text: 'Preview message whose effect is off the page: it stays until dismissed.', persistent: true }])}>Add a persistent message</Button>
          </div>
        </section>

        <section className={styles.section} aria-labelledby="pv-signin">
          <h2 id="pv-signin">Sign-in card, every ruled outcome</h2>
          <div className={styles.grid}>
            {signInVariants.map((v) => (
              <figure key={v.label} className={styles.figure}>
                <figcaption className={styles.overline}>{v.label}</figcaption>
                <SignInCard layout="embedded" manageFocus={false} notice={v.notice} workspace={v.workspace ?? null} onSubmit={() => undefined} onForgetWorkspace={() => undefined} />
              </figure>
            ))}
            <figure className={styles.figure}>
              <figcaption className={styles.overline}>Submitting</figcaption>
              <SignInCard layout="embedded" manageFocus={false} submitting onSubmit={() => undefined} />
            </figure>
          </div>
        </section>
      </div>
    </AppFrame>
  );
}

const CHIP_TONES: ChipTone[] = ['neutral', 'info', 'success', 'warning', 'danger', 'accent', 'violet'];

/** B-4 step 1 primitives with illustrative props (no B-4 copy exists in `copy/` yet; these strings are preview-only). */
function PrimitivesPreview() {
  const live = useLiveRegion();
  const [a, setA] = useState<boolean | 'mixed'>(false);
  const [group, setGroup] = useState<boolean | 'mixed'>('mixed');
  const [open, setOpen] = useState(false);
  const cancel = useRef<HTMLButtonElement>(null);
  const heading = useRef<HTMLHeadingElement>(null);
  return (
    <section className={styles.section} aria-labelledby="pv-primitives">
      <h2 id="pv-primitives" ref={heading} tabIndex={-1}>
        B-4 primitives
      </h2>
      <LiveRegion message={live.message} />
      <div className={styles.row}>
        <Checkbox label="Preview option" checked={a} onCheckedChange={(next) => { setA(next); live.announce(next ? 'Preview: ticked.' : 'Preview: unticked.'); }} />
        <Checkbox label="Preview group (mixed)" checked={group} onCheckedChange={setGroup} />
        <Checkbox label="Preview unavailable" checked={false} onCheckedChange={() => undefined} unavailable describedBy="pv-why" />
      </div>
      <p id="pv-why" className={styles.muted}>Preview reason: shown as visible text, tied by aria-describedby.</p>
      <div className={styles.row}>
        {CHIP_TONES.map((t) => (
          <Chip key={t} tone={t}>
            Preview {t}
          </Chip>
        ))}
      </div>
      <p className={styles.muted}>
        Date: <DateTime value="2026-09-22T06:05:00Z" form="date" timeZone="UTC" /> · Date and time: <DateTime value="2026-10-22T06:05:00Z" form="date-time" timeZone="UTC" />
      </p>
      <div className={styles.row}>
        <Button onClick={() => setOpen(true)}>Open a preview dialog</Button>
      </div>
      <Dialog
        open={open}
        onOpenChange={setOpen}
        title="Preview dialog"
        description={<p>Focus starts on Cancel; Esc closes and returns focus to the opener.</p>}
        initialFocusRef={cancel}
        fallbackFocusRef={heading}
      >
        <Button ref={cancel} onClick={() => setOpen(false)}>
          Cancel
        </Button>
        <Button variant="primary" onClick={() => setOpen(false)}>
          Done
        </Button>
      </Dialog>
    </section>
  );
}

const PEOPLE_SCENARIOS: { label: string; person: User }[] = [
  { label: 'Person page', person: PERSON_ACTIVE },
  { label: 'Your own page', person: PERSON_SELF },
  { label: 'Deleted (was active)', person: PERSON_DELETED_ACTIVE },
  { label: 'Deleted (was switched off)', person: PERSON_DELETED_SWITCHED_OFF },
];

/** Preview answers: success, each served refusal (fixture), or a lost connection. */
type PreviewAnswer = 'ok' | 'unreachable' | number;

/** B-4 step 2: delete and restore a person, driven by the one fixture copy. Preview-only labels are not product copy. */
function PeoplePreview() {
  const h1 = useRef<HTMLHeadingElement>(null);
  const [person, setPerson] = useState<User>(PERSON_ACTIVE);
  const [answer, setAnswer] = useState<PreviewAnswer>('ok');
  const archived = person.archived_at !== null;
  const refusals = archived ? RESTORE_REFUSALS : DELETE_REFUSALS;
  const pick = (p: User) => {
    setPerson(p);
    setAnswer('ok');
  };
  const deletePerson = async (): Promise<DeletePersonResult> => {
    if (answer === 'unreachable') return { kind: 'unreachable' };
    if (typeof answer === 'number') return DELETE_REFUSALS[answer] ?? { kind: 'server-error' };
    setPerson((p) => ({ ...p, archived_at: '2026-10-27T03:00:00Z', roles: [] }));
    return { kind: 'deleted' };
  };
  const restorePerson = async (): Promise<RestorePersonResult> => {
    if (answer === 'unreachable') return { kind: 'unreachable' };
    if (typeof answer === 'number') return RESTORE_REFUSALS[answer] ?? { kind: 'server-error' };
    const restored = { ...person, archived_at: null };
    setPerson(restored);
    return { kind: 'restored', person: restored };
  };
  return (
    <section className={styles.section} aria-labelledby="pv-people" data-fixture={FIXTURE_ONLY}>
      <h2 id="pv-people">B-4 delete and restore a person</h2>
      <div className={styles.row}>
        {PEOPLE_SCENARIOS.map((s) => (
          <Button key={s.label} size="sm" aria-pressed={person.id === s.person.id} onClick={() => pick(s.person)}>
            {s.label}
          </Button>
        ))}
      </div>
      <div className={styles.row}>
        <Button size="sm" aria-pressed={answer === 'ok'} onClick={() => setAnswer('ok')}>
          Preview answer: success
        </Button>
        {refusals.map((r, i) => (
          <Button key={r.rule} size="sm" aria-pressed={answer === i} onClick={() => setAnswer(i)}>
            {`Preview answer: refusal ${i + 1}`}
          </Button>
        ))}
        <Button size="sm" aria-pressed={answer === 'unreachable'} onClick={() => setAnswer('unreachable')}>
          Preview answer: unreachable
        </Button>
      </div>
      <div className={styles.row}>
        <h3 ref={h1} tabIndex={-1}>
          {person.id === VIEWER_ID ? PERSON.ownName(person.display_name) : person.display_name}
        </h3>
        {archived ? <Chip tone="neutral">{PERSON.deletedChip}</Chip> : null}
      </div>
      <p className={styles.muted}>{person.email}</p>
      <PersonLifecycleActions
        person={person}
        viewerId={VIEWER_ID}
        canDelete
        canAssign
        deletePerson={deletePerson}
        restorePerson={restorePerson}
        onGiveRole={() => undefined}
        fallbackFocusRef={h1}
        timeZone="UTC"
      />
    </section>
  );
}

/** Illustrative only (see fixtures/bulk.ts): small enough to reach the over-cap state on one page. Never the served cap. */
const PREVIEW_CAP = 3;

/**
 * B-4 step 3: the bulk surface on a sample list, driven by the one fixture copy. The list (this component) is the
 * surface's parent: it calls `useBulkSelection`, renders the column, the bar, the banner zone and the after-message, and
 * re-reads after a finished run (here: drops the applied rows). Preview-only labels are not product copy.
 */
function BulkPreview() {
  const h1 = useRef<HTMLHeadingElement>(null);
  const [archivedView, setArchivedView] = useState(false);
  const [page, setPage] = useState(0);
  const [offered, setOffered] = useState(true);
  const [answer, setAnswer] = useState(0);
  const [gone, setGone] = useState<Set<string>>(() => new Set());
  const [lastRun, setLastRun] = useState<{ ids: readonly string[]; key: string } | null>(null);
  const rows = (archivedView ? SUPPLIER_ARCHIVED : SUPPLIER_PAGES[page] ?? []).filter((r) => !gone.has(r.id));
  const bulk = useBulkSelection({ scopeKey: `${archivedView ? 'archived' : 'live'}|${page}`, offered });
  const pageIds = rows.map((r) => r.id);

  const run = async (ids: readonly string[], key: string): Promise<BulkResult> => {
    setLastRun({ ids, key });
    const result = BULK_PREVIEW_ANSWERS[answer]!.answer(ids.length);
    if (result.kind === 'done') setGone((g) => new Set([...g, ...ids.slice(0, result.applied)]));
    return result;
  };

  return (
    <section className={styles.section} aria-labelledby="pv-bulk" data-fixture={FIXTURE_ONLY}>
      <h2 id="pv-bulk">B-4 bulk selection</h2>
      <div className={styles.row}>
        <Button size="sm" aria-pressed={!archivedView} onClick={() => { setArchivedView(false); setPage(0); }}>
          Preview view: live
        </Button>
        <Button size="sm" aria-pressed={archivedView} onClick={() => { setArchivedView(true); setPage(0); }}>
          Preview view: archived
        </Button>
        <Button size="sm" aria-pressed={offered} onClick={() => setOffered((o) => !o)}>
          Preview: holds the delete permission
        </Button>
      </div>
      <div className={styles.row}>
        {BULK_PREVIEW_ANSWERS.map((a, i) => (
          <Button key={a.label} size="sm" aria-pressed={answer === i} onClick={() => setAnswer(i)}>
            {`Preview answer: ${a.label}`}
          </Button>
        ))}
      </div>
      <h3 ref={h1} tabIndex={-1}>
        Sample suppliers
      </h3>
      <BulkNotices bulk={bulk} />
      <BulkBar
        bulk={bulk}
        mode={archivedView ? 'restore' : 'delete'}
        nouns={SUPPLIER_NOUNS}
        maxMembers={PREVIEW_CAP}
        run={run}
        onReread={() => undefined}
        fallbackFocusRef={h1}
      />
      <table className={styles.table}>
        <thead>
          <tr>
            <BulkHeaderCheckbox bulk={bulk} pageIds={pageIds} />
            <th scope="col">Name</th>
            <th scope="col">Code</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.id} className={bulk.isSelected(r.id) ? styles.selectedRow : undefined}>
              <BulkRowCheckbox bulk={bulk} id={r.id} firstColumn={r.name} />
              <td>{r.name}</td>
              <td>{r.code}</td>
            </tr>
          ))}
        </tbody>
      </table>
      {!archivedView ? (
        <div className={styles.row}>
          <span className={styles.muted}>{`${rows.length} shown`}</span>
          {page > 0 ? (
            <Button size="sm" onClick={() => setPage((p) => p - 1)}>
              Newer
            </Button>
          ) : null}
          {page < SUPPLIER_PAGES.length - 1 ? (
            <Button size="sm" onClick={() => setPage((p) => p + 1)}>
              Older
            </Button>
          ) : null}
        </div>
      ) : null}
      <BulkResultMessage bulk={bulk} fallbackFocusRef={h1} />
      <p className={styles.muted}>{lastRun ? `Preview: last run sent ${lastRun.ids.length} ids with key ${lastRun.key}` : 'Preview: nothing sent yet'}</p>
    </section>
  );
}

const CHECKLIST_SCENARIOS: { label: string; role: Role; canEdit: boolean }[] = [
  { label: 'Custom role, editing', role: CUSTOM_ROLE, canEdit: true },
  { label: 'Custom role, margin flag', role: MARGIN_ROLE, canEdit: true },
  { label: 'Built-in: owner', role: OWNER_ROLE, canEdit: true },
  { label: 'Built-in: purchasing_manager', role: PURCHASING_MANAGER_ROLE, canEdit: true },
  { label: 'Viewer without role.edit', role: CUSTOM_ROLE, canEdit: false },
];
// The served catalogue plus one illustrative unlabelled code, so the fallback row (server description only) shows.
const PREVIEW_PERMISSIONS = [...PERMISSIONS, UNLABELLED_PERMISSION];

/**
 * B-4 step 4: the permission checklist, driven by the one fixture copy. The preview is the route: it owns the heading
 * and re-reads (replaces the role) after a save. Preview-only labels are not product copy.
 */
function PermissionsPreview() {
  const h1 = useRef<HTMLHeadingElement>(null);
  const [scenario, setScenario] = useState(0);
  const [role, setRole] = useState<Role>(CHECKLIST_SCENARIOS[0]!.role);
  const [answer, setAnswer] = useState<PreviewAnswer>('ok');
  const pick = (i: number) => {
    setScenario(i);
    setRole(CHECKLIST_SCENARIOS[i]!.role);
    setAnswer('ok');
  };
  const save = async (codes: readonly string[]): Promise<SaveRoleResult> => {
    if (answer === 'unreachable') return { kind: 'unreachable' };
    if (typeof answer === 'number') return SAVE_REFUSALS[answer] ?? { kind: 'server-error' };
    const saved = { ...role, permission_codes: [...codes] };
    setRole(saved);
    return { kind: 'saved', role: saved };
  };
  return (
    <section className={styles.section} aria-labelledby="pv-perms" data-fixture={FIXTURE_ONLY}>
      <h2 id="pv-perms">B-4 permission checklist</h2>
      <div className={styles.row}>
        {CHECKLIST_SCENARIOS.map((s, i) => (
          <Button key={s.label} size="sm" aria-pressed={scenario === i} onClick={() => pick(i)}>
            {s.label}
          </Button>
        ))}
      </div>
      <div className={styles.row}>
        <Button size="sm" aria-pressed={answer === 'ok'} onClick={() => setAnswer('ok')}>
          Preview save: success
        </Button>
        {SAVE_REFUSALS.map((r, i) => (
          <Button key={r.rule} size="sm" aria-pressed={answer === i} onClick={() => setAnswer(i)}>
            {`Preview save: refusal ${i + 1}`}
          </Button>
        ))}
        <Button size="sm" aria-pressed={answer === 'unreachable'} onClick={() => setAnswer('unreachable')}>
          Preview save: unreachable
        </Button>
      </div>
      <h3 ref={h1} tabIndex={-1}>
        {role.name}
      </h3>
      <p className={styles.muted}>{role.description}</p>
      <PermissionChecklist role={role} permissions={PREVIEW_PERMISSIONS} canEdit={CHECKLIST_SCENARIOS[scenario]!.canEdit} save={save} fallbackFocusRef={h1} />
    </section>
  );
}

interface PickerScenario {
  label: string;
  person: User;
  viewerId: string;
  offered: OfferedRole[];
  proposals: RoleGrantProposal[];
  roleView: boolean;
  canConfirm: boolean;
  showProposals: boolean;
}

const PICKER_SCENARIOS: PickerScenario[] = [
  { label: 'Admin opens Jonas (F-7)', person: JONAS, viewerId: VIEWER_ID, offered: OFFERED_JONAS_BY_ADMIN, proposals: [PROPOSAL_BY_OTHER], roleView: true, canConfirm: false, showProposals: true },
  { label: 'Owner opens Jonas', person: JONAS, viewerId: OWNER_VIEWER_ID, offered: OFFERED_JONAS_BY_OWNER, proposals: [PROPOSAL_FINANCE, PROPOSAL_EXPIRED], roleView: true, canConfirm: true, showProposals: true },
  { label: 'Holds a conflicting pair', person: HELD_PAIR_PERSON, viewerId: VIEWER_ID, offered: OFFERED_HELD_PAIR_BY_ADMIN, proposals: [], roleView: true, canConfirm: false, showProposals: true },
  { label: "Can give roles, can't view them", person: JONAS, viewerId: VIEWER_ID, offered: OFFERED_JONAS_BY_ADMIN, proposals: [], roleView: false, canConfirm: false, showProposals: true },
  { label: 'Your own page', person: SELF_ADMIN, viewerId: VIEWER_ID, offered: OFFERED_SELF_ADMIN, proposals: [], roleView: true, canConfirm: false, showProposals: false },
];

type PickerAnswer = 'ok' | 'refused' | 'unreachable' | 'reread-fails';

/**
 * B-4 step 5: the role picker and the Waiting group, driven by the one fixture copy. The preview is the person page: it
 * owns the heading, composes the two siblings (RED M-3) and plays the caller contract — a re-read sets new props, then
 * resolves. It does not recompute served verdicts (only the server can), so after a save the offered list stays as
 * loaded. Preview-only labels are not product copy.
 */
function RolePickerPreview() {
  const h1 = useRef<HTMLHeadingElement>(null);
  const [scenario, setScenario] = useState(0);
  const s = PICKER_SCENARIOS[scenario]!;
  const [person, setPerson] = useState<User>(s.person);
  const [proposals, setProposals] = useState<RoleGrantProposal[]>(s.proposals);
  const [answer, setAnswer] = useState<PickerAnswer>('ok');
  const pick = (i: number) => {
    setScenario(i);
    setPerson(PICKER_SCENARIOS[i]!.person);
    setProposals(PICKER_SCENARIOS[i]!.proposals);
    setAnswer('ok');
  };
  const roleOf = (id: string) => ROLES.find((r) => r.id === id)!;
  const fail = (): RoleActionFailure | null => (answer === 'unreachable' ? { kind: 'unreachable' } : null);
  const pendingRoleIds = new Set(proposals.filter((p) => !p.is_expired).map((p) => p.role.id));
  return (
    <section className={styles.section} aria-labelledby="pv-roles" data-fixture={FIXTURE_ONLY}>
      <h2 id="pv-roles">B-4 role picker and Waiting for an owner</h2>
      <div className={styles.row}>
        {PICKER_SCENARIOS.map((x, i) => (
          <Button key={x.label} size="sm" aria-pressed={scenario === i} onClick={() => pick(i)}>
            {x.label}
          </Button>
        ))}
      </div>
      <div className={styles.row}>
        {(['ok', 'refused', 'unreachable', 'reread-fails'] as const).map((a) => (
          <Button key={a} size="sm" aria-pressed={answer === a} onClick={() => setAnswer(a)}>
            {`Preview answers: ${a}`}
          </Button>
        ))}
      </div>
      <h3 ref={h1} tabIndex={-1}>
        {s.viewerId === person.id ? PERSON.ownName(person.display_name) : person.display_name}
      </h3>
      <div key={scenario} className={styles.stack}>
        <RolePicker
          person={person}
          viewerId={s.viewerId}
          offered={s.offered}
          pendingRoleIds={pendingRoleIds}
          roles={s.roleView ? ROLES : undefined}
          permissions={s.roleView ? PERMISSIONS : undefined}
          actions={{
            assign: async (id) => {
              if (answer === 'refused') return PICKER_REFUSALS.assignProposalPending;
              const f = fail();
              if (f) return f;
              setPerson((p) => ({ ...p, roles: [...p.roles, { id, name: roleOf(id).name }] }));
              return { kind: 'given', role: roleOf(id) };
            },
            propose: async (id) => {
              if (answer === 'refused') return PICKER_REFUSALS.proposeAlreadyPending;
              const f = fail();
              if (f) return f;
              const proposal = { ...PROPOSAL_FINANCE, id: `${PROPOSAL_FINANCE.id.slice(0, -4)}${String(proposals.length + 10).padStart(4, '0')}`, role: { id, name: roleOf(id).name } };
              setProposals((ps) => [...ps, proposal]);
              return { kind: 'proposed', proposal };
            },
            revoke: async (id) => {
              if (answer === 'refused') return PICKER_REFUSALS.revokeLastOwner;
              const f = fail();
              if (f) return f;
              setPerson((p) => ({ ...p, roles: p.roles.filter((r) => r.id !== id) }));
              return { kind: 'taken-away' };
            },
            reread: async () => (answer === 'reread-fails' ? { kind: 'failed', failure: 'unreachable' } : { kind: 'ok' }),
          }}
        />
        {s.showProposals ? (
          <RoleProposals
            proposals={page(proposals)}
            canConfirm={s.canConfirm}
            marginExposure={person.margin_exposure}
            fallbackFocusRef={h1}
            timeZone="UTC"
            actions={{
              confirm: async (id) => {
                const f = fail();
                if (f) return f;
                if (answer === 'refused') return PROPOSAL_REFUSALS.confirmerIsProposer;
                const p = proposals.find((x) => x.id === id)!;
                setProposals((ps) => ps.filter((x) => x.id !== id));
                setPerson((x) => ({ ...x, roles: [...x.roles, p.role] }));
                return { kind: 'confirmed', proposal: { ...p, status: 'confirmed' } };
              },
              reject: async (id) => {
                const f = fail();
                if (f) return f;
                const p = proposals.find((x) => x.id === id)!;
                setProposals((ps) => ps.filter((x) => x.id !== id));
                return { kind: 'rejected', proposal: { ...p, status: 'rejected' } };
              },
              withdraw: async (id) => {
                const f = fail();
                if (f) return f;
                if (answer === 'refused') return PROPOSAL_REFUSALS.notTheProposer;
                const p = proposals.find((x) => x.id === id)!;
                setProposals((ps) => ps.filter((x) => x.id !== id));
                return { kind: 'withdrawn', proposal: { ...p, status: 'cancelled' } };
              },
              reread: async () => ({ kind: 'ok' }),
              onPage: () => {},
            }}
          />
        ) : null}
      </div>
    </section>
  );
}
