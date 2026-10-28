import { useState } from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { Checkbox } from '@/components/ui/Checkbox';

function Controlled({ initial = false, unavailable = false, onChange }: { initial?: boolean | 'mixed'; unavailable?: boolean; onChange?: (next: boolean) => void }) {
  const [checked, setChecked] = useState<boolean | 'mixed'>(initial);
  return (
    <Checkbox
      label="Finance"
      checked={checked}
      unavailable={unavailable}
      onCheckedChange={(next) => {
        onChange?.(next);
        setChecked(next);
      }}
    />
  );
}

describe('Checkbox (RED M-5)', () => {
  it('toggles on click and Space while available', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(<Controlled onChange={onChange} />);
    const box = screen.getByRole('checkbox', { name: 'Finance' });
    await user.click(box);
    expect(box).toBeChecked();
    await user.keyboard(' ');
    expect(box).not.toBeChecked();
    expect(onChange.mock.calls).toEqual([[true], [false]]);
  });

  it('unavailable: aria-disabled, never [disabled], focusable, and click and Space change nothing', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(<Controlled unavailable onChange={onChange} />);
    const box = screen.getByRole('checkbox', { name: 'Finance' });
    expect(box).toHaveAttribute('aria-disabled', 'true');
    expect(box).not.toHaveAttribute('disabled');
    expect(box).not.toBeDisabled();
    await user.tab();
    expect(box).toHaveFocus();
    await user.click(box);
    expect(box).not.toBeChecked();
    await user.keyboard(' ');
    expect(box).not.toBeChecked();
    expect(onChange).not.toHaveBeenCalled();
  });

  it('unavailable and checked stays checked on click', async () => {
    const user = userEvent.setup();
    render(<Controlled initial unavailable />);
    const box = screen.getByRole('checkbox', { name: 'Finance' });
    await user.click(box);
    expect(box).toBeChecked();
  });

  it("'mixed' uses the DOM indeterminate property, not aria-checked; a click asks for true", async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(<Controlled initial="mixed" onChange={onChange} />);
    const box = screen.getByRole<HTMLInputElement>('checkbox', { name: 'Finance' });
    expect(box).toBePartiallyChecked();
    expect(box.indeterminate).toBe(true);
    expect(box).not.toHaveAttribute('aria-checked');
    await user.click(box);
    expect(onChange).toHaveBeenCalledWith(true);
    expect(box).toBeChecked();
    expect(box.indeterminate).toBe(false);
  });

  it('an unavailable mixed box is still mixed after a click', async () => {
    const user = userEvent.setup();
    render(<Controlled initial="mixed" unavailable />);
    const box = screen.getByRole<HTMLInputElement>('checkbox', { name: 'Finance' });
    await user.click(box);
    expect(box.indeterminate).toBe(true);
    expect(box).not.toBeChecked();
  });

  it('describedBy ties the visible reasons; hideLabel keeps the accessible name', () => {
    render(
      <>
        <p id="r1">Only an owner can give this role.</p>
        <p id="r2">Can&apos;t be held with Purchasing.</p>
        <Checkbox label="Select Ana" hideLabel checked={false} onCheckedChange={() => {}} unavailable describedBy="r1 r2" />
      </>,
    );
    const box = screen.getByRole('checkbox', { name: 'Select Ana' });
    expect(box).toHaveAccessibleDescription("Only an owner can give this role. Can't be held with Purchasing.");
  });

  it('the hit area is the label (min-size token class on the label, not the 16 px box)', () => {
    render(<Checkbox label="Finance" checked={false} onCheckedChange={() => {}} />);
    const box = screen.getByRole('checkbox', { name: 'Finance' });
    expect(box.closest('label')).not.toBeNull();
  });
});
