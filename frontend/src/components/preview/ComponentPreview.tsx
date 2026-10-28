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
import { PERSON } from '@/copy/people';
import type { User } from '@/lib/api/users';
import type { DeletePersonResult, RestorePersonResult } from '@/lib/people/results';
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
        Date: <DateTime value="2026-09-22T06:05:00Z" form="date" /> · Date and time: <DateTime value="2026-10-22T06:05:00Z" form="date-time" />
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
