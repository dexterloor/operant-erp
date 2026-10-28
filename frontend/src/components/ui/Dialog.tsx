'use client';

import * as RadixDialog from '@radix-ui/react-dialog';
import { useLayoutEffect, useRef, type ReactNode, type RefObject } from 'react';
import styles from './Dialog.module.css';

export interface DialogRefusal {
  /** Changes whenever a NEW refusal is shown; focus moves to its heading once per id. */
  id: string;
  content: ReactNode;
  /** The refusal's focusable heading (e.g. `Banner`'s heading ref, which carries tabIndex -1). */
  headingRef: RefObject<HTMLElement | null>;
}

export interface DialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** The dialog's title; it names the dialog (`aria-labelledby`). */
  title: ReactNode;
  /** Body paragraph(s); all of it describes the dialog (`aria-describedby`, R4-4 L-6). */
  description: ReactNode;
  /** The action row (Cancel + the primary or danger action). */
  children: ReactNode;
  /** R4-4 L-6: Cancel in Delete/Restore person, Archive in the bulk archive dialog. Decided by the caller. */
  initialFocusRef: RefObject<HTMLElement | null>;
  /**
   * 4b-4 / RED M-7: where focus goes on close when the opener no longer exists (the row left the view, the Delete
   * button is gone after success) — the page's `<h1>`, supplied by the route because it owns it.
   */
  fallbackFocusRef: RefObject<HTMLElement | null>;
  /**
   * 4b-4: a refusal renders INSIDE the dialog, its heading takes focus, the focus trap holds, and the dialog stays open.
   * The dialog closes only when the caller sets `open` to false (on success or Cancel/Esc; never while `busy`).
   */
  refusal?: DialogRefusal | null;
  /**
   * b4-final-red M-1 / spar R-2: while the caller's request is in flight the dialog stays open — Escape and a
   * pointer-down outside are ignored, so an answer that arrives is never lost with a closed dialog. The caller hides its
   * Cancel for the same span (nothing operable that does nothing). B-3b carry: every BFF call has a client timeout that
   * resolves `Unreachable`, so this can never hold the dialog open indefinitely.
   */
  busy?: boolean;
  tone?: 'default' | 'danger';
}

/**
 * Radix Dialog wrapper (modal: focus trapped, Esc closes unless `busy`). Focus on open goes to `initialFocusRef`; on
 * close it returns to the element that had focus when the dialog opened if that element is still in the document, else
 * to `fallbackFocusRef` (Radix alone would drop it on `body` once the opener is removed — RED M-7).
 */
export function Dialog({ open, onOpenChange, title, description, children, initialFocusRef, fallbackFocusRef, refusal, busy = false, tone = 'default' }: DialogProps) {
  const opener = useRef<HTMLElement | null>(null);

  const refusalId = refusal?.id;
  const refusalHeading = refusal?.headingRef;
  // A LAYOUT effect, so focus moves in the same commit that inserts the refusal (b4-step3-progress.md "Flake"): with a
  // passive effect the banner was in the DOM for one task while focus was still on the button that sent the request.
  useLayoutEffect(() => {
    if (refusalId !== undefined) refusalHeading?.current?.focus();
  }, [refusalId, refusalHeading]);

  return (
    <RadixDialog.Root open={open} onOpenChange={onOpenChange}>
      <RadixDialog.Portal>
        <RadixDialog.Overlay className={styles.overlay} />
        <RadixDialog.Content
          className={`${styles.content} ${tone === 'danger' ? styles.danger : ''}`.trim()}
          onOpenAutoFocus={(e) => {
            // Radix records the previously focused element before this event; it is still document.activeElement.
            opener.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
            e.preventDefault();
            initialFocusRef.current?.focus();
          }}
          onEscapeKeyDown={(e) => {
            if (busy) e.preventDefault();
          }}
          onInteractOutside={(e) => {
            if (busy) e.preventDefault();
          }}
          onCloseAutoFocus={(e) => {
            e.preventDefault();
            const back = opener.current && opener.current.isConnected ? opener.current : fallbackFocusRef.current;
            opener.current = null;
            back?.focus();
          }}
        >
          <RadixDialog.Title className={styles.title}>{title}</RadixDialog.Title>
          <RadixDialog.Description asChild>
            <div className={styles.body}>{description}</div>
          </RadixDialog.Description>
          {refusal ? <div className={styles.refusal}>{refusal.content}</div> : null}
          <div className={styles.actions}>{children}</div>
        </RadixDialog.Content>
      </RadixDialog.Portal>
    </RadixDialog.Root>
  );
}
