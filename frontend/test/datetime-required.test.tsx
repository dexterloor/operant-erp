import { describe, expect, it } from 'vitest';
import { DateTime } from '@/components/ui/DateTime';
import { ArchivedPersonBanner } from '@/components/people/ArchivedPersonBanner';
import { PersonLifecycleActions } from '@/components/people/PersonLifecycleActions';
import { RoleProposals } from '@/components/roles/RoleProposals';
import { PERSON_ACTIVE, VIEWER_ID } from '@/components/preview/fixtures/people';

// Rule M-4 / A-9: `timeZone` is REQUIRED on DateTime, PersonLifecycleActions and RoleProposals (and ArchivedPersonBanner,
// which passes it to DateTime). The pin is the type checker (`npm run typecheck`, part of `npm run check`): each element
// below omits the prop, and each `@ts-expect-error` fails the check if the prop ever becomes optional again.

export const missing = [
  // @ts-expect-error timeZone is required (rule M-4)
  <DateTime key="1" value="2026-10-27T23:30:00Z" form="date" />,
  // @ts-expect-error timeZone is required (rule M-4)
  <ArchivedPersonBanner key="2" archivedAt="2026-10-27T23:30:00Z" />,
  // @ts-expect-error timeZone is required (rule M-4)
  <PersonLifecycleActions key="3" person={PERSON_ACTIVE} viewerId={VIEWER_ID} canDelete canAssign onPageTookOver={() => undefined} fallbackFocusRef={{ current: null }} deletePerson={async () => ({ kind: 'unreachable' })} restorePerson={async () => ({ kind: 'unreachable' })} />,
  // @ts-expect-error timeZone is required (rule M-4)
  <RoleProposals key="4" proposals={{ items: [], next_cursor: null, previous_cursor: null, tenant_has_rows: false }} canConfirm={false} marginExposure={false} fallbackFocusRef={{ current: null }} actions={{ confirm: async () => ({ kind: 'unreachable' }), reject: async () => ({ kind: 'unreachable' }), withdraw: async () => ({ kind: 'unreachable' }), reread: async () => ({ kind: 'ok' }), onPage: () => undefined }} />,
];

describe('rule M-4 — timeZone is required (pinned by the type checker)', () => {
  it('the four elements exist (the assertion is the typecheck of this file)', () => {
    expect(missing).toHaveLength(4);
  });

  it('DateTime no longer reads the browser zone: the formatting module offers no zone reader', async () => {
    const dates = await import('@/lib/format/dates');
    expect('viewerTimeZone' in dates).toBe(false);
  });
});
