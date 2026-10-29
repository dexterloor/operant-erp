import { existsSync } from 'node:fs';
import path from 'node:path';
import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { AppFrame } from '@/components/shell/AppFrame';
import { Sidebar, SidebarRail } from '@/components/shell/Sidebar';
import { MobileNav } from '@/components/shell/MobileNav';
import { TooltipProvider } from '@/components/ui/Tooltip';
import { NAVIGATION } from '@/lib/navigation';
import { BUILT_PAGE_HREFS } from '@/lib/routes';

const ALL = NAVIGATION.flatMap((g) => g.items.map((i) => i.permission));
/**
 * b3b-followup C-3: the menu now shows built pages only. The tests below that exercise the permission filter, the rail
 * tooltips and the 320 px menu predate it and use unbuilt items, so they pass every navigation href as built — moved
 * deliberately, so they keep testing what they were written for.
 */
const EVERY_HREF = NAVIGATION.flatMap((g) => g.items.map((i) => i.href));
const frameProps = {
  currentPath: '/purchase-orders/7',
  user: { displayName: 'Sample Person', email: 'person@example.com' },
  workspace: { displayName: 'Display Name', signInName: 'typed-sign-in-name' },
  theme: 'light' as const,
  onThemeChange: vi.fn(),
  onChangePassword: vi.fn(),
  onSignOut: vi.fn(),
};

describe('Sidebar', () => {
  it('renders only items whose code is held; a role lacking a code has no item, and no group without items', () => {
    const codes = ['forecast.view', 'purchase_order.view', 'goods_receipt.view', 'inventory_item.view'];
    render(<Sidebar permissionCodes={codes} currentPath="/purchase-orders" builtHrefs={EVERY_HREF} />);
    const nav = screen.getByRole('navigation', { name: 'Main' });
    const links = within(nav).getAllByRole('link').map((a) => a.textContent);
    expect(links).toEqual(['Overview', 'Purchase orders', 'Goods receipts', 'Items']);
    expect(within(nav).queryByText('Suppliers')).toBeNull();
    expect(within(nav).queryByText('Sales')).toBeNull();
    expect(within(nav).queryByText('Administration')).toBeNull();
    expect(nav.querySelector('[aria-disabled]')).toBeNull();
  });

  it('marks the current route with aria-current=page', () => {
    render(<Sidebar permissionCodes={ALL} currentPath="/purchase-orders/42" builtHrefs={EVERY_HREF} />);
    expect(screen.getByRole('link', { name: 'Purchase orders' })).toHaveAttribute('aria-current', 'page');
    expect(screen.getByRole('link', { name: 'Goods receipts' })).not.toHaveAttribute('aria-current');
  });
});

describe('Rail tooltips (WCAG 1.4.13, M-3)', () => {
  it('show on keyboard focus and are dismissed with Esc without moving focus', async () => {
    const user = userEvent.setup();
    render(
      <TooltipProvider delayDuration={0}>
        <SidebarRail permissionCodes={['forecast.view', 'invoice.view']} currentPath="/overview" builtHrefs={EVERY_HREF} />
      </TooltipProvider>,
    );
    await user.tab();
    const overview = screen.getByRole('link', { name: 'Overview' });
    expect(overview).toHaveFocus();
    expect(await screen.findByRole('tooltip')).toHaveTextContent('Overview');
    await user.keyboard('{Escape}');
    expect(screen.queryByRole('tooltip')).toBeNull();
    expect(overview).toHaveFocus();
  });

  it('show on hover', async () => {
    const user = userEvent.setup();
    render(
      <TooltipProvider delayDuration={0}>
        <SidebarRail permissionCodes={['invoice.view']} currentPath="/overview" builtHrefs={EVERY_HREF} />
      </TooltipProvider>,
    );
    await user.hover(screen.getByRole('link', { name: 'Invoices' }));
    expect(await screen.findByRole('tooltip')).toHaveTextContent('Invoices');
  });
});

describe('320 px menu (MobileNav)', () => {
  it('traps focus inside while open, Esc closes it, focus returns to the menu button', async () => {
    const user = userEvent.setup();
    render(
      <TooltipProvider>
        <button>before</button>
        <MobileNav permissionCodes={['forecast.view', 'invoice.view']} currentPath="/overview" builtHrefs={EVERY_HREF} />
        <button>after</button>
      </TooltipProvider>,
    );
    const trigger = screen.getByRole('button', { name: 'Open menu' });
    await user.click(trigger);
    const dialog = await screen.findByRole('dialog');
    const focusables = within(dialog).getAllByRole('link').concat(within(dialog).getAllByRole('button'));
    expect(focusables.length).toBe(3);
    for (let i = 0; i < 8; i += 1) {
      await user.tab();
      expect(dialog.contains(document.activeElement)).toBe(true);
    }
    await user.keyboard('{Escape}');
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(screen.getByRole('button', { name: 'Open menu' })).toHaveFocus();
  });
});

describe('AppFrame', () => {
  it('the skip link is the first tab stop and targets the main region', async () => {
    const user = userEvent.setup();
    render(<AppFrame {...frameProps} permissionCodes={ALL}><h1>Page</h1></AppFrame>);
    await user.tab();
    const skip = screen.getByRole('link', { name: 'Skip to content' });
    expect(skip).toHaveFocus();
    expect(skip).toHaveAttribute('href', '#main-content');
    expect(document.getElementById('main-content')?.tagName).toBe('MAIN');
  });

  it('D14: shows the display name only to tenant_settings.view holders', () => {
    const { unmount } = render(<AppFrame {...frameProps} permissionCodes={ALL}><p>x</p></AppFrame>);
    expect(screen.getByText('Display Name')).toBeInTheDocument();
    unmount();
    render(<AppFrame {...frameProps} permissionCodes={['invoice.view']}><p>x</p></AppFrame>);
    expect(screen.getByText('typed-sign-in-name')).toBeInTheDocument();
    expect(screen.queryByText('Display Name')).toBeNull();
  });

  it('moves focus to the page h1 after a route change, not on first render', () => {
    const { rerender } = render(<AppFrame {...frameProps} permissionCodes={ALL}><h1>First</h1></AppFrame>);
    expect(screen.getByRole('heading', { name: 'First' })).not.toHaveFocus();
    act(() => {
      rerender(<AppFrame {...frameProps} currentPath="/invoices" permissionCodes={ALL}><h1>Second</h1></AppFrame>);
    });
    const h1 = screen.getByRole('heading', { name: 'Second' });
    expect(h1).toHaveFocus();
    expect(h1).toHaveAttribute('tabindex', '-1');
  });
});

describe('built pages only (b3b-followup C-3, F-1)', () => {
  const links = (el: HTMLElement) => within(el).getAllByRole('link').map((a) => a.getAttribute('href'));

  it('by default every code shows exactly the built pages, each a page folder on disk', () => {
    render(<Sidebar permissionCodes={ALL} currentPath="/admin/users" />);
    expect(links(screen.getByRole('navigation', { name: 'Main' }))).toEqual([...BUILT_PAGE_HREFS]);
    for (const href of BUILT_PAGE_HREFS) expect(existsSync(path.join(__dirname, '..', 'src/app/(app)', href, 'page.tsx')), href).toBe(true);
  });

  it('the rail shows the same built pages', () => {
    render(
      <TooltipProvider>
        <SidebarRail permissionCodes={ALL} currentPath="/admin/users" />
      </TooltipProvider>,
    );
    expect(links(screen.getByRole('navigation', { name: 'Main' }))).toEqual([...BUILT_PAGE_HREFS]);
  });

  it('codes that open no built page: no navigation landmark in the sidebar or the rail (brand kept), and no menu button', () => {
    const codes = ['purchase_order.view', 'invoice.view'];
    const a = render(<Sidebar permissionCodes={codes} currentPath="/" />);
    expect(screen.queryByRole('navigation')).toBeNull();
    expect(a.container.querySelector('[aria-hidden="true"]')).not.toBeNull();
    a.unmount();
    const b = render(
      <TooltipProvider>
        <SidebarRail permissionCodes={codes} currentPath="/" />
      </TooltipProvider>,
    );
    expect(screen.queryByRole('navigation')).toBeNull();
    b.unmount();
    render(<MobileNav permissionCodes={codes} currentPath="/" />);
    expect(screen.queryByRole('button')).toBeNull();
  });
});
