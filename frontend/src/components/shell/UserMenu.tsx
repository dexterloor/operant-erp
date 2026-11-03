'use client';

import * as Menu from '@radix-ui/react-dropdown-menu';
import { Check, ChevronDown, KeyRound, LogOut, Monitor, Moon, Sun } from 'lucide-react';
import type { ThemePreference } from '@/styles/tokens';
import { SHELL } from '@/copy/shell';
import { initials } from '@/lib/session';
import styles from './UserMenu.module.css';

export interface UserMenuProps {
  displayName: string;
  email: string;
  theme: ThemePreference;
  onThemeChange: (pref: ThemePreference) => void;
  onChangePassword: () => void;
  onSignOut: () => void;
}

const THEMES: { value: ThemePreference; label: string; Icon: typeof Sun }[] = [
  { value: 'light', label: SHELL.themeLight, Icon: Sun },
  { value: 'dark', label: SHELL.themeDark, Icon: Moon },
  { value: 'system', label: SHELL.themeSystem, Icon: Monitor },
];

/**
 * §4.5 user menu: display name, email, Change password, Theme (Light / Dark / Match system, D4), Sign out.
 * There is deliberately no "Keyboard shortcuts" entry (B-3a brief; DECISIONS §4.5's flag is not adopted here).
 * Actions are callbacks; nothing here signs anyone out or changes a password (B-3b wires them).
 */
export function UserMenu({ displayName, email, theme, onThemeChange, onChangePassword, onSignOut }: UserMenuProps) {
  return (
    <Menu.Root modal={false}>
      <Menu.Trigger className={styles.trigger}>
        <span className={styles.avatar} aria-hidden="true">
          {initials(displayName)}
        </span>
        <span className={styles.name}>{displayName}</span>
        <span className="visually-hidden">{SHELL.accountMenuSuffix}</span>
        <ChevronDown aria-hidden="true" width={16} height={16} strokeWidth={1.5} className={styles.chevron} />
      </Menu.Trigger>
      <Menu.Portal>
        <Menu.Content className={styles.content} align="end" sideOffset={6} collisionPadding={8}>
          <div className={styles.who}>
            <span className={styles.whoName}>{displayName}</span>
            <span className={styles.whoEmail}>{email}</span>
          </div>
          <Menu.Separator className={styles.sep} />
          <Menu.Item className={styles.item} onSelect={onChangePassword}>
            <KeyRound aria-hidden="true" width={16} height={16} strokeWidth={1.5} className={styles.itemIcon} />
            {SHELL.changePassword}
          </Menu.Item>
          <Menu.Separator className={styles.sep} />
          <Menu.Label className={styles.label}>{SHELL.theme}</Menu.Label>
          <Menu.RadioGroup value={theme} onValueChange={(v) => onThemeChange(v as ThemePreference)}>
            {THEMES.map(({ value, label, Icon }) => (
              <Menu.RadioItem key={value} value={value} className={styles.item} onSelect={(e) => e.preventDefault()}>
                <Icon aria-hidden="true" width={16} height={16} strokeWidth={1.5} className={styles.itemIcon} />
                <span className={styles.grow}>{label}</span>
                <Menu.ItemIndicator>
                  <Check aria-hidden="true" width={16} height={16} strokeWidth={1.5} className={styles.check} />
                </Menu.ItemIndicator>
              </Menu.RadioItem>
            ))}
          </Menu.RadioGroup>
          <Menu.Separator className={styles.sep} />
          <Menu.Item className={styles.item} onSelect={onSignOut}>
            <LogOut aria-hidden="true" width={16} height={16} strokeWidth={1.5} className={styles.itemIcon} />
            {SHELL.signOut}
          </Menu.Item>
        </Menu.Content>
      </Menu.Portal>
    </Menu.Root>
  );
}
