'use client';

import { useRef } from 'react';
import { RESTORE_PERSON } from '@/copy/people';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';
import type { PersonDialogProps } from './DeletePersonDialog';

/**
 * §2.3 "Restore {name}?" — ONE body, chosen by the served `is_active` of the deleted person (R1, RED M-3: never both).
 * Initial focus is Cancel (R4-4 L-6: it lets someone sign in again); refusals render inside (4b-4).
 */
export function RestorePersonDialog({ open, onOpenChange, name, isActive, busy, notice, onConfirm, fallbackFocusRef }: PersonDialogProps & { isActive: boolean }) {
  const cancel = useRef<HTMLButtonElement>(null);
  const heading = useRef<HTMLParagraphElement>(null);
  return (
    <Dialog
      open={open}
      onOpenChange={onOpenChange}
      title={RESTORE_PERSON.title(name)}
      description={<p>{isActive ? RESTORE_PERSON.bodyActive(name) : RESTORE_PERSON.bodySwitchedOff(name)}</p>}
      initialFocusRef={cancel}
      busy={busy}
      fallbackFocusRef={fallbackFocusRef}
      refusal={
        notice
          ? {
              id: String(notice.id),
              headingRef: heading,
              content: (
                // R1-1: focus moves to the heading and carries the announcement, so the banner takes no live role.
                <Banner tone="danger" live="none" heading={notice.heading} ref={heading}>
                  <p>{notice.body}</p>
                </Banner>
              ),
            }
          : null
      }
    >
      {/*
        M-1 / R-2: no Cancel while the request is in flight; it returns when the answer arrives. Focus stays on the primary
        button, or moves to the dialog itself if it was still on Cancel (a mouse click does not focus a button in macOS
        Safari/Firefox); it never leaves the dialog (b4-final-red L-D1).
      */}
      {busy ? null : (
        <Button ref={cancel} onClick={() => onOpenChange(false)}>
          {RESTORE_PERSON.cancel}
        </Button>
      )}
      <Button variant="primary" loading={busy} onClick={onConfirm}>
        {RESTORE_PERSON.confirm}
      </Button>
    </Dialog>
  );
}
