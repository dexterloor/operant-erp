import { existsSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AppFrame } from '@/components/shell/AppFrame';
import { PageError } from '@/components/shell/PageError';
import { DocumentArrival, resetDocumentArrivalForTest } from '@/components/shell/DocumentArrival';
import type { ComponentType } from 'react';
import RootErrorFile from '@/app/error';
import AppErrorFile from '@/app/(app)/error';
import RootNotFound from '@/app/not-found';

// Copy typed here independently of src/copy. WENT_WRONG and the ST-5 strings are RULED/CONFIRMED; the body and the two
// titles are CONFIRMED / NEW by UX (ux-confirm-followup.md §1a, §1c, §1d).
const WENT_WRONG = 'Something went wrong on our side.';
const LOAD_FAILED = "This page couldn't be loaded.";
const NOT_FOUND_HEADING = "We couldn't find that.";
const NOT_FOUND_BODY = 'It may have been deleted, or you may not have access.';
const ERROR_TITLE = 'Something went wrong on our side · ERP';
const NOT_FOUND_TITLE = "We couldn't find that · ERP";

// The title and the arrival flag are document-global: reset both, so an earlier render cannot satisfy an assertion
// (ux-confirm-followup-red.md items 10, 11).
beforeEach(() => {
  document.title = '';
  resetDocumentArrivalForTest();
});

/** As after the document load: the arrival flag has flipped, so the next page mounted is a client navigation. */
async function arrive() {
  const first = render(<DocumentArrival />);
  await act(async () => {
    await Promise.resolve();
  });
  first.unmount();
}

const ROOT = path.resolve(__dirname, '..');
const secretError = () => Object.assign(new Error('SECRET-DETAIL'), { digest: 'D123' });

// Next passes every boundary `{ error, reset }`. The files deliberately declare no props (they never read the error), so
// the tests render them as Next does — with an error carrying a secret message and digest — to catch a boundary that
// starts reading it.
type BoundaryProps = { error: Error & { digest?: string }; reset: () => void };
const RootError = RootErrorFile as unknown as ComponentType<BoundaryProps>;
const AppError = AppErrorFile as unknown as ComponentType<BoundaryProps>;

describe('page-level error and not-found files (b3b-followup C-2, F-4)', () => {
  it('both error boundaries exist and open with "use client"; the root not-found exists', () => {
    for (const f of ['src/app/error.tsx', 'src/app/(app)/error.tsx']) {
      const abs = path.join(ROOT, f);
      expect(existsSync(abs), f).toBe(true);
      expect(readFileSync(abs, 'utf8').trimStart().startsWith("'use client';"), f).toBe(true);
    }
    expect(existsSync(path.join(ROOT, 'src/app/not-found.tsx'))).toBe(true);
  });

  for (const [name, Boundary] of [
    ['root error.tsx', RootError],
    ['(app)/error.tsx', AppError],
  ] as const) {
    it(`${name}: our heading, body and Retry; never the error message or digest`, () => {
      render(<Boundary error={secretError()} reset={vi.fn()} />);
      expect(screen.getByRole('heading', { level: 1, name: WENT_WRONG })).toBeInTheDocument();
      expect(screen.getByText(LOAD_FAILED)).toBeInTheDocument();
      expect(screen.getByRole('button', { name: 'Retry' })).toBeInTheDocument();
      expect(document.body.textContent).not.toContain('SECRET-DETAIL');
      expect(document.body.textContent).not.toContain('D123');
    });
  }

  it('Retry calls the injected onRetry once, and the heading has focus after mount', () => {
    const onRetry = vi.fn();
    render(<PageError onRetry={onRetry} />);
    expect(screen.getByRole('heading', { level: 1, name: WENT_WRONG })).toHaveFocus();
    act(() => {
      fireEvent.click(screen.getByRole('button', { name: 'Retry' }));
    });
    expect(onRetry).toHaveBeenCalledTimes(1);
  });

  it('root not-found: the two ST-5 strings and a link to the start page', () => {
    render(<RootNotFound />);
    expect(screen.getByRole('heading', { level: 1, name: NOT_FOUND_HEADING })).toBeInTheDocument();
    expect(screen.getByText(NOT_FOUND_BODY)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Go to your start page' })).toHaveAttribute('href', '/');
  });

  it('final-red M-1: the frame-less root pages are the one main landmark; inside the frame there is still exactly one', () => {
    const a = render(<RootError error={secretError()} reset={vi.fn()} />);
    expect(screen.getAllByRole('main')).toHaveLength(1);
    a.unmount();
    const b = render(<RootNotFound />);
    expect(screen.getAllByRole('main')).toHaveLength(1);
    b.unmount();
    render(
      <AppFrame
        permissionCodes={[]}
        currentPath="/admin/users"
        user={{ displayName: 'Sample Person', email: 'person@example.com' }}
        workspace={{ signInName: 'acme' }}
        theme="light"
        onThemeChange={vi.fn()}
        onChangePassword={vi.fn()}
        onSignOut={vi.fn()}
      >
        <AppError error={secretError()} reset={vi.fn()} />
      </AppFrame>,
    );
    expect(screen.getAllByRole('main')).toHaveLength(1);
  });
});

describe('tab titles and not-found focus (ux-confirm-followup.md §1c, §1d; its RED M-1)', () => {
  it('the error page names itself in the tab: its h1 without the full stop', () => {
    render(<PageError onRetry={vi.fn()} />);
    expect(document.title).toBe(ERROR_TITLE);
  });

  it('root not-found on the document load: the tab title is set, and focus stays where it is', () => {
    render(<RootNotFound />);
    expect(document.title).toBe(NOT_FOUND_TITLE);
    expect(screen.getByRole('heading', { level: 1, name: NOT_FOUND_HEADING })).not.toHaveFocus();
  });

  it('root not-found after a client navigation: focus to the h1 and the tab title', async () => {
    await arrive();
    render(<RootNotFound />);
    expect(screen.getByRole('heading', { level: 1, name: NOT_FOUND_HEADING })).toHaveFocus();
    expect(document.title).toBe(NOT_FOUND_TITLE);
  });

  it('the not-found file keeps its metadata title (what the 404 HTML carries before hydration)', async () => {
    const mod = await import('@/app/not-found');
    expect((mod as { metadata?: { title?: string } }).metadata?.title).toBe("We couldn't find that");
  });
});
