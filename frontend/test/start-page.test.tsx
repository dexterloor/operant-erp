import { act, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { StartPage } from '@/components/shell/StartPage';
import { DocumentArrival, resetDocumentArrivalForTest } from '@/components/shell/DocumentArrival';

// ux-copy-b3b.md C2-1 (Q-6): the `/` start page when no built page is open to the viewer. Typed here independently of
// src/copy so a drift in either is caught.
const H1 = "There's nothing here for you yet.";
const NO_CODES = "You don't have access to anything in this workspace yet. Your workspace administrator can give you a role.";
const HAS_CODES = "The pages your roles give you access to aren't available yet.";

afterEach(() => {
  resetDocumentArrivalForTest();
  document.title = '';
});

describe('the start page (C2-1)', () => {
  it('no permission codes → the "no access" body; no link, no action', () => {
    render(<StartPage permissionCodes={[]} />);
    expect(screen.getByRole('heading', { level: 1, name: H1 })).toBeInTheDocument();
    expect(screen.getByText(NO_CODES)).toBeInTheDocument();
    expect(screen.queryByText(HAS_CODES)).toBeNull();
    expect(screen.queryAllByRole('link')).toHaveLength(0);
    expect(screen.queryAllByRole('button')).toHaveLength(0);
  });

  it('some permission codes (none opening a built page) → the "not available yet" body', () => {
    render(<StartPage permissionCodes={['invoice.view']} />);
    expect(screen.getByText(HAS_CODES)).toBeInTheDocument();
    expect(screen.queryByText(NO_CODES)).toBeNull();
  });

  it('on the document load it is ordinary content: focus does not move and nothing is announced', () => {
    render(
      <>
        <DocumentArrival />
        <StartPage permissionCodes={[]} />
      </>,
    );
    expect(screen.getByRole('heading', { level: 1 })).not.toHaveFocus();
    expect(screen.queryByRole('status')).toBeNull();
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('T-ST (ii): on a client navigation to "/", focus moves to the h1 and the title updates', async () => {
    const first = render(<DocumentArrival />);
    await act(async () => {
      await Promise.resolve();
    });
    first.unmount();
    render(<StartPage permissionCodes={['invoice.view']} />);
    const h1 = screen.getByRole('heading', { level: 1, name: H1 });
    expect(h1).toHaveFocus();
    expect(h1).toHaveAttribute('tabindex', '-1');
    expect(document.title).toBe("There's nothing here for you yet · ERP");
  });
});
