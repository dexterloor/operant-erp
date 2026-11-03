'use client';

import * as RadixTooltip from '@radix-ui/react-tooltip';
import type { ReactNode } from 'react';
import styles from './Tooltip.module.css';

/**
 * WCAG 2.2 SC 1.4.13 (review M-3), all three rules, by NOT customising Radix away from its defaults:
 *  - dismissible: Esc closes it without moving focus or the pointer (Radix onEscapeKeyDown default);
 *  - hoverable: the pointer can move onto the tooltip without it closing (disableHoverableContent stays false);
 *  - persistent: it stays until the trigger loses hover/focus or the user dismisses it (no auto-hide timer).
 * Opens on hover AND keyboard focus (§4.6). A tooltip is never the only place a reason lives (H-3a).
 * Timing from DECISIONS §6: 120 ms in; the provider is shared by the app frame.
 */
export function TooltipProvider({ children, delayDuration = 120 }: { children: ReactNode; delayDuration?: number }) {
  return (
    <RadixTooltip.Provider delayDuration={delayDuration} skipDelayDuration={300} disableHoverableContent={false}>
      {children}
    </RadixTooltip.Provider>
  );
}

export function Tooltip({ content, children, side = 'right' }: { content: ReactNode; children: ReactNode; side?: 'top' | 'right' | 'bottom' | 'left' }) {
  return (
    <RadixTooltip.Root>
      <RadixTooltip.Trigger asChild>{children}</RadixTooltip.Trigger>
      <RadixTooltip.Portal>
        <RadixTooltip.Content side={side} sideOffset={8} className={styles.content} collisionPadding={8}>
          {content}
        </RadixTooltip.Content>
      </RadixTooltip.Portal>
    </RadixTooltip.Root>
  );
}
