import { act, render, screen, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Button } from '@/components/ui/Button';
import { StatusMessages } from '@/components/ui/StatusMessages';
import { TooltipProvider } from '@/components/ui/Tooltip';
import { NotFound, PermissionDenied, Refetchable, RefetchFailedBanner, RegionError } from '@/components/states/PageStates';

describe('Button', () => {
  it('never uses the native disabled attribute; aria-disabled keeps it focusable with a visible, linked reason', async () => {
    const user = userEvent.setup();
    const onClick = vi.fn();
    render(<Button unavailableReason="Visible reason." onClick={onClick}>Act</Button>);
    const b = screen.getByRole('button', { name: 'Act' });
    expect(b).not.toBeDisabled();
    expect(b).toHaveAttribute('aria-disabled', 'true');
    expect(b).toHaveAccessibleDescription('Visible reason.');
    expect(screen.getByText('Visible reason.')).toBeVisible();
    await user.tab();
    expect(b).toHaveFocus();
    await user.click(b);
    expect(onClick).not.toHaveBeenCalled();
  });

  it('loading keeps the label, sets aria-busy and ignores clicks', async () => {
    const user = userEvent.setup();
    const onClick = vi.fn();
    render(<Button loading onClick={onClick}>Place order</Button>);
    const b = screen.getByRole('button', { name: 'Place order' });
    expect(b).toHaveAttribute('aria-busy', 'true');
    await user.click(b);
    expect(onClick).not.toHaveBeenCalled();
  });
});

describe('Page states (RULED copy)', () => {
  it('permission denied: heading, body, "Go to your start page" link to /; focus on the h1 (ux-copy-b3b.md C2-3)', () => {
    render(<PermissionDenied resourcePlural="purchase orders" permissionName="Purchasing" />);
    const h1 = screen.getByRole('heading', { level: 1, name: "You don't have access to purchase orders." });
    expect(h1).toHaveFocus();
    expect(screen.getByText(/needs the/)).toHaveTextContent('This needs the Purchasing permission. Your workspace administrator can grant it.');
    expect(screen.getByRole('link', { name: 'Go to your start page' })).toHaveAttribute('href', '/');
    expect(screen.queryByText('Back to Overview')).toBeNull();
  });

  it('not found: identical for missing and not yours', () => {
    render(<NotFound listHref="/items" />);
    expect(screen.getByRole('heading', { level: 1, name: "We couldn't find that." })).toHaveFocus();
    expect(screen.getByText('It may have been deleted, or you may not have access.')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Back to the list' })).toHaveAttribute('href', '/items');
  });

  it('region error: the caller\'s heading and body; focus moves to its heading, so it takes no live role (R1-1); Retry calls back', async () => {
    const user = userEvent.setup();
    const retry = vi.fn();
    const { container } = render(<RegionError heading="Something went wrong on our side." body="The list of people couldn't be loaded." onRetry={retry} />);
    expect(screen.getByRole('heading', { name: 'Something went wrong on our side.' })).toHaveFocus();
    expect(screen.getByText("The list of people couldn't be loaded.")).toBeInTheDocument();
    expect(container.querySelector('[role="status"], [aria-live]')).toBeNull();
    expect(container.textContent).not.toMatch(/unaffected/);
    await user.click(screen.getByRole('button', { name: 'Retry' }));
    expect(retry).toHaveBeenCalled();
  });

  it('refetch failed: the earlier data stays; the R1-1 heading and body are the caller\'s; no live role of its own (the caller\'s one region speaks, FE-R3), focus does not move', () => {
    const { container } = render(<RefetchFailedBanner heading="We couldn't reach the server." body="The list couldn't be updated, so it still shows the earlier results." onRetry={() => {}} />);
    expect(screen.getByText("We couldn't reach the server.")).toBeInTheDocument();
    expect(container.querySelector('[role="status"], [role="alert"], [aria-live]')).toBeNull();
    expect(screen.getByText("The list couldn't be updated, so it still shows the earlier results.")).toBeInTheDocument();
    expect(document.body).toHaveFocus();
  });

  it('refetching sets aria-busy and leaves content untouched (no dimming wrapper styles)', () => {
    const { container } = render(<Refetchable busy><button>Row link</button></Refetchable>);
    // FE-R3 L-1: the wrapper builds no status region; the caller owns one, mounted from first render.
    expect(container.querySelector('[role="status"], [aria-live]')).toBeNull();
    const btn = screen.getByRole('button', { name: 'Row link' });
    const region = btn.parentElement as HTMLElement;
    expect(region).toHaveAttribute('aria-busy', 'true');
    expect(region.style.opacity).toBe('');
    expect(region.getAttribute('inert')).toBeNull();
  });
});

describe('Status messages (8 s, paused on hover and focus)', () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  const renderStack = (onDismiss: (id: string) => void, persistent = false) =>
    render(
      <TooltipProvider>
        <StatusMessages messages={[{ id: 'a', text: 'Effect named here.', persistent }]} onDismiss={onDismiss} />
      </TooltipProvider>,
    );

  it('auto-dismisses after 8 s, in a polite status region', () => {
    const onDismiss = vi.fn();
    renderStack(onDismiss);
    expect(screen.getByRole('status')).toHaveAttribute('aria-live', 'polite');
    act(() => vi.advanceTimersByTime(7999));
    expect(onDismiss).not.toHaveBeenCalled();
    act(() => vi.advanceTimersByTime(1));
    expect(onDismiss).toHaveBeenCalledWith('a');
  });

  it('pauses while hovered and resumes with the remaining time', () => {
    const onDismiss = vi.fn();
    renderStack(onDismiss);
    const msg = screen.getByText('Effect named here.').parentElement as HTMLElement;
    act(() => vi.advanceTimersByTime(5000));
    fireEvent.mouseEnter(msg);
    act(() => vi.advanceTimersByTime(60000));
    expect(onDismiss).not.toHaveBeenCalled();
    fireEvent.mouseLeave(msg);
    act(() => vi.advanceTimersByTime(2900));
    expect(onDismiss).not.toHaveBeenCalled();
    act(() => vi.advanceTimersByTime(200));
    expect(onDismiss).toHaveBeenCalledTimes(1);
  });

  it('pauses while focus is inside it', () => {
    const onDismiss = vi.fn();
    renderStack(onDismiss);
    act(() => screen.getByRole('button', { name: 'Dismiss' }).focus());
    act(() => vi.advanceTimersByTime(60000));
    expect(onDismiss).not.toHaveBeenCalled();
  });

  it('a message naming an off-page effect stays until dismissed', () => {
    const onDismiss = vi.fn();
    renderStack(onDismiss, true);
    act(() => vi.advanceTimersByTime(600000));
    expect(onDismiss).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Dismiss' }));
    expect(onDismiss).toHaveBeenCalledWith('a');
  });

  it('never steals focus when a message appears', () => {
    const outside = document.createElement('button');
    document.body.appendChild(outside);
    outside.focus();
    renderStack(vi.fn());
    expect(outside).toHaveFocus();
    outside.remove();
  });
});
