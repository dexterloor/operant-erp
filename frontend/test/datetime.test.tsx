import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { DateTime } from '@/components/ui/DateTime';
import { TooltipProvider } from '@/components/ui/Tooltip';

describe('DateTime (DT-1, DT-3, DT-4)', () => {
  it('shows DT text in <time dateTime=raw>, and the full instant in the tooltip on keyboard focus', async () => {
    const user = userEvent.setup();
    render(
      <TooltipProvider delayDuration={0}>
        <DateTime value="2026-10-22T06:05:00Z" form="date-time" timeZone="Asia/Manila" />
      </TooltipProvider>,
    );
    const time = screen.getByText('22 Oct 2026, 14:05');
    expect(time.tagName).toBe('TIME');
    expect(time).toHaveAttribute('datetime', '2026-10-22T06:05:00Z');
    await user.tab();
    expect(time).toHaveFocus();
    expect((await screen.findByRole('tooltip')).textContent).toBe('22 Oct 2026, 14:05 (UTC+08:00)');
  });

  it('date form shows the date alone', () => {
    render(
      <TooltipProvider>
        <DateTime value="2026-09-02T06:05:00Z" form="date" timeZone="UTC" />
      </TooltipProvider>,
    );
    expect(screen.getByText('2 Sep 2026')).toHaveAttribute('tabindex', '0');
  });
});
