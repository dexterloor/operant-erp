import { StrictMode, useState } from 'react';
import { act, render, renderHook, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { LiveRegion, useLiveRegion } from '@/components/ui/LiveRegion';

// A surface that swaps its branch on success (the people page shape, RED M-6): the region sits ABOVE the branch.
function Surface() {
  const live = useLiveRegion();
  const [done, setDone] = useState(false);
  return (
    <section>
      <LiveRegion message={live.message} />
      {done ? (
        <p>Archived view</p>
      ) : (
        <button
          type="button"
          onClick={() => {
            setDone(true);
            live.announce('Ana was deleted.');
          }}
        >
          Delete
        </button>
      )}
      <button type="button" onClick={live.clear}>
        Dismiss message
      </button>
    </section>
  );
}

describe('LiveRegion (R4-4 L-2, RED M-6)', () => {
  it('is mounted empty at first render and the same node carries every later message', async () => {
    const user = userEvent.setup();
    render(<Surface />);
    const region = screen.getByRole('status');
    expect(region).toBeEmptyDOMElement();
    expect(region).toHaveAttribute('aria-live', 'polite');
    await user.click(screen.getByRole('button', { name: 'Delete' }));
    expect(screen.getByRole('status')).toBe(region);
    expect(region).toHaveTextContent('Ana was deleted.');
    await user.click(screen.getByRole('button', { name: 'Dismiss message' }));
    expect(screen.getByRole('status')).toBe(region);
    expect(region).toBeEmptyDOMElement();
  });

  it('under StrictMode: still one region, empty at first, announced once', async () => {
    const user = userEvent.setup();
    render(
      <StrictMode>
        <Surface />
      </StrictMode>,
    );
    expect(screen.getAllByRole('status')).toHaveLength(1);
    const region = screen.getByRole('status');
    expect(region).toBeEmptyDOMElement();
    await user.click(screen.getByRole('button', { name: 'Delete' }));
    expect(screen.getByRole('status')).toBe(region);
    expect(region.textContent).toBe('Ana was deleted.');
  });

  it('announce replaces the text; announce and clear are stable across renders', () => {
    const { result, rerender } = renderHook(() => useLiveRegion());
    const { announce, clear } = result.current;
    act(() => result.current.announce('One'));
    act(() => result.current.announce('Two'));
    expect(result.current.message).toBe('Two');
    rerender();
    expect(result.current.announce).toBe(announce);
    expect(result.current.clear).toBe(clear);
    act(() => result.current.clear());
    expect(result.current.message).toBe('');
    render(<LiveRegion message="Two" visuallyHidden />);
    expect(screen.getByRole('status')).toHaveClass('visually-hidden');
  });
});
