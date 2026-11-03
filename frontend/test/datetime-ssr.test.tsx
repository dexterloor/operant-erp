import { render } from '@testing-library/react';
import { renderToString } from 'react-dom/server';
import { afterEach, describe, expect, it } from 'vitest';
import { DateTime } from '@/components/ui/DateTime';
import { ArchivedPersonBanner } from '@/components/people/ArchivedPersonBanner';
import { PersonLifecycleActions } from '@/components/people/PersonLifecycleActions';
import { RoleProposals } from '@/components/roles/RoleProposals';
import { TooltipProvider } from '@/components/ui/Tooltip';
import { PERSON_DELETED_ACTIVE, VIEWER_ID } from '@/components/preview/fixtures/people';
import type { RoleGrantProposal } from '@/lib/api/proposals';

// T-M4a (b3b-design.md §11, rule M-4; A-9): `timeZone` is required and the zone is never read in render, so the server's
// HTML and the browser's render agree whatever zone each process runs in. Each case is rendered with react-dom/server
// under one process zone and with the client renderer under another, and the `<time>` elements must be identical.
// (Only the `<time>` markup is compared: React mints different useId prefixes on the server and the client.)

const ORIGINAL_TZ = process.env.TZ;
afterEach(() => {
  process.env.TZ = ORIGINAL_TZ;
});

const times = (root: ParentNode) => [...root.querySelectorAll('time')].map((t) => t.outerHTML);

function serverTimes(node: React.ReactElement, tz: string): string[] {
  process.env.TZ = tz;
  const html = renderToString(<TooltipProvider>{node}</TooltipProvider>);
  const doc = new DOMParser().parseFromString(`<body>${html}</body>`, 'text/html');
  return times(doc);
}

function clientTimes(node: React.ReactElement, tz: string): string[] {
  process.env.TZ = tz;
  const { container, unmount } = render(<TooltipProvider>{node}</TooltipProvider>);
  const out = times(container);
  unmount();
  return out;
}

const SERVER_TZ = 'Pacific/Kiritimati';
const CLIENT_TZ = 'America/Los_Angeles';

const proposal = {
  id: '9a1c2d3e-4f50-4a6b-8c7d-9e0f1a2b3c4d',
  role: { id: '1b2c3d4e-5f60-4a7b-8c9d-0e1f2a3b4c5d', name: 'Finance' },
  user: { id: VIEWER_ID, display_name: 'X' },
  proposed_by: { id: VIEWER_ID, display_name: 'X' },
  status: 'pending',
  proposed_at: '2026-10-27T23:30:00Z',
  expires_at: '2026-11-10T23:30:00Z',
} as unknown as RoleGrantProposal;

describe('T-M4a — the same markup on the server and in the browser, in different process zones', () => {
  it('the process zones really differ for this instant (the test can fail)', () => {
    const at = new Date('2026-10-27T23:30:00Z');
    process.env.TZ = SERVER_TZ;
    const a = at.toString();
    process.env.TZ = CLIENT_TZ;
    const b = at.toString();
    expect(a).not.toBe(b);
  });

  it.each([
    ['DateTime date', <DateTime key="a" value="2026-10-27T23:30:00Z" form="date" timeZone="Asia/Manila" />],
    ['DateTime date-time', <DateTime key="b" value="2026-10-27T23:30:00Z" form="date-time" timeZone="Asia/Manila" />],
    ['ArchivedPersonBanner', <ArchivedPersonBanner key="c" archivedAt="2026-10-27T23:30:00Z" timeZone="Europe/Lisbon" />],
    [
      'PersonLifecycleActions (deleted person)',
      <PersonLifecycleActions
        key="d"
        person={{ ...PERSON_DELETED_ACTIVE, archived_at: '2026-10-27T23:30:00Z' }}
        viewerId={VIEWER_ID}
        canDelete
        canAssign
        fallbackFocusRef={{ current: null }}
        timeZone="Asia/Manila"
        deletePerson={async () => ({ kind: 'unreachable' })}
        restorePerson={async () => ({ kind: 'unreachable' })}
      />,
    ],
    [
      'RoleProposals',
      <RoleProposals
        key="e"
        proposals={{ items: [proposal], next_cursor: null, previous_cursor: null, tenant_has_rows: true }}
        canConfirm={false}
        marginExposure={false}
        fallbackFocusRef={{ current: null }}
        timeZone="Asia/Manila"
        actions={{ confirm: async () => ({ kind: 'unreachable' }), reject: async () => ({ kind: 'unreachable' }), withdraw: async () => ({ kind: 'unreachable' }), reread: async () => ({ kind: 'ok' }), onPage: () => undefined }}
      />,
    ],
  ])('%s', (_name, node) => {
    const server = serverTimes(node, SERVER_TZ);
    const client = clientTimes(node, CLIENT_TZ);
    expect(server.length).toBeGreaterThan(0);
    expect(client).toEqual(server);
  });
});
