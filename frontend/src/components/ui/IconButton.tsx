'use client';

import { forwardRef, type ButtonHTMLAttributes, type ReactNode } from 'react';
import { Tooltip } from './Tooltip';
import styles from './IconButton.module.css';

export interface IconButtonProps extends Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'disabled' | 'aria-label'> {
  /** Required: the accessible name, also shown as the tooltip on hover and keyboard focus (§4.6). */
  label: string;
  icon: ReactNode;
  size?: 'sm' | 'md';
  tone?: 'default' | 'shell';
}

/** 28/36 px boxes, both above the 24 × 24 px minimum hit area (WCAG 2.5.8; A4: never relying on the spacing exception). */
export const IconButton = forwardRef<HTMLButtonElement, IconButtonProps>(function IconButton(
  { label, icon, size = 'md', tone = 'default', className, type = 'button', ...rest },
  ref,
) {
  return (
    <Tooltip content={label} side="bottom">
      <button {...rest} ref={ref} type={type} aria-label={label} className={[styles.button, styles[size], styles[tone], className ?? ''].join(' ').trim()}>
        {icon}
      </button>
    </Tooltip>
  );
});
