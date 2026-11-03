'use client';

import { useEffect, useRef, useState, type ReactNode } from 'react';
import { X } from 'lucide-react';
import { interaction } from '@/styles/tokens';
import { SHELL } from '@/copy/shell';
import { IconButton } from './IconButton';
import styles from './StatusMessages.module.css';

export interface StatusMessage {
  id: string;
  /** Names the effect (§5.8, ST-11b) — never "Saved" alone. Supplied by the caller from the UX docs. */
  text: ReactNode;
  /** A message naming an effect not visible on the page stays until dismissed (§5.8). */
  persistent?: boolean;
}

/**
 * §5.8 status messages: bottom-right stack, polite role="status", never steals focus, dismissible, auto-dismiss after
 * 8 s paused while the pointer is over the message or focus is inside it. Errors never use this (inline or banners).
 */
export function StatusMessages({ messages, onDismiss, dismissAfterMs = interaction['toast-dismiss'] }: { messages: readonly StatusMessage[]; onDismiss: (id: string) => void; dismissAfterMs?: number }) {
  return (
    <div className={styles.stack} role="status" aria-live="polite">
      {messages.map((m) => (
        <MessageItem key={m.id} message={m} onDismiss={onDismiss} dismissAfterMs={dismissAfterMs} />
      ))}
    </div>
  );
}

function MessageItem({ message, onDismiss, dismissAfterMs }: { message: StatusMessage; onDismiss: (id: string) => void; dismissAfterMs: number }) {
  const [hovered, setHovered] = useState(false);
  const [focused, setFocused] = useState(false);
  const remaining = useRef(dismissAfterMs);
  const startedAt = useRef<number>(0);
  const paused = hovered || focused;

  useEffect(() => {
    if (message.persistent || paused) return;
    startedAt.current = Date.now();
    const timer = setTimeout(() => onDismiss(message.id), remaining.current);
    return () => {
      clearTimeout(timer);
      remaining.current = Math.max(0, remaining.current - (Date.now() - startedAt.current));
    };
  }, [paused, message.persistent, message.id, onDismiss]);

  return (
    <div
      className={styles.message}
      data-paused={paused || undefined}
      onMouseEnter={() => setHovered(true)}
      onMouseLeave={() => setHovered(false)}
      onFocus={() => setFocused(true)}
      onBlur={(e) => {
        if (!e.currentTarget.contains(e.relatedTarget as Node | null)) setFocused(false);
      }}
    >
      <p className={styles.text}>{message.text}</p>
      <IconButton label={SHELL.dismiss} size="sm" icon={<X aria-hidden="true" width={16} height={16} strokeWidth={1.5} />} onClick={() => onDismiss(message.id)} />
      {message.persistent ? null : <span aria-hidden="true" className={styles.timer} style={{ animationDuration: `${dismissAfterMs}ms` }} />}
    </div>
  );
}
