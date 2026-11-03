import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { UserMenu } from '@/components/shell/UserMenu';

function setup(theme: 'light' | 'dark' | 'system' = 'light') {
  const props = { displayName: 'Sample Person', email: 'person@example.com', theme, onThemeChange: vi.fn(), onChangePassword: vi.fn(), onSignOut: vi.fn() };
  render(<UserMenu {...props} />);
  return props;
}

describe('UserMenu', () => {
  it('offers Change password, Theme (Light / Dark / Match system) and Sign out — and no Keyboard shortcuts', async () => {
    const user = userEvent.setup();
    setup('dark');
    await user.click(screen.getByRole('button', { name: 'Sample Person · Account' }));
    const menu = await screen.findByRole('menu');
    expect(menu).toHaveTextContent('Sample Person');
    expect(menu).toHaveTextContent('person@example.com');
    expect(screen.getByRole('menuitem', { name: 'Change password' })).toBeInTheDocument();
    expect(screen.getByRole('menuitem', { name: 'Sign out' })).toBeInTheDocument();
    const radios = screen.getAllByRole('menuitemradio');
    expect(radios.map((r) => r.textContent)).toEqual(['Light', 'Dark', 'Match system']);
    expect(screen.getByRole('menuitemradio', { name: 'Dark' })).toHaveAttribute('aria-checked', 'true');
    expect(menu).not.toHaveTextContent(/keyboard/i);
  });

  it('reports the chosen theme and the two actions through callbacks', async () => {
    const user = userEvent.setup();
    const p = setup();
    await user.click(screen.getByRole('button', { name: 'Sample Person · Account' }));
    await user.click(await screen.findByRole('menuitemradio', { name: 'Match system' }));
    expect(p.onThemeChange).toHaveBeenCalledWith('system');
    await user.click(screen.getByRole('menuitem', { name: 'Sign out' }));
    expect(p.onSignOut).toHaveBeenCalledTimes(1);
    await user.click(screen.getByRole('button', { name: 'Sample Person · Account' }));
    await user.click(await screen.findByRole('menuitem', { name: 'Change password' }));
    expect(p.onChangePassword).toHaveBeenCalledTimes(1);
  });
});
