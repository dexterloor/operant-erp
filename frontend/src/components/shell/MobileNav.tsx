'use client';

import * as Dialog from '@radix-ui/react-dialog';
import { Menu as MenuIcon, X } from 'lucide-react';
import { useState } from 'react';
import { SHELL } from '@/copy/shell';
import { Sidebar, type SidebarProps } from './Sidebar';
import styles from './MobileNav.module.css';

/**
 * Below 768 px the sidebar becomes a menu button (§4.1 reflow at 320 CSS px). The menu is a modal sheet: focus is
 * trapped inside while open, Esc closes it, and focus returns to the menu button (Radix Dialog).
 */
export function MobileNav(props: Omit<SidebarProps, 'onNavigate'>) {
  const [open, setOpen] = useState(false);
  return (
    <Dialog.Root open={open} onOpenChange={setOpen}>
      <Dialog.Trigger className={styles.trigger} aria-label={open ? SHELL.closeMenu : SHELL.openMenu}>
        <MenuIcon aria-hidden="true" width={20} height={20} strokeWidth={1.5} />
      </Dialog.Trigger>
      <Dialog.Portal>
        <Dialog.Overlay className={styles.overlay} />
        <Dialog.Content className={styles.sheet} aria-describedby={undefined}>
          <Dialog.Title className="visually-hidden">{SHELL.mainNav}</Dialog.Title>
          <Dialog.Close className={styles.close} aria-label={SHELL.closeMenu}>
            <X aria-hidden="true" width={20} height={20} strokeWidth={1.5} />
          </Dialog.Close>
          <Sidebar {...props} onNavigate={() => setOpen(false)} />
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}
