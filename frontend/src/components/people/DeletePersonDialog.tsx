'use client';

import { useRef, type ReactNode, type RefObject } from 'react';
import { DELETE_PERSON } from '@/copy/people';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';

/** A banner shown inside an open dialog: a refusal or an interrupted request. A new `id` moves focus to its heading. */
export interface PersonDialogNotice {
  id: number;
  heading: string;
  /** A server sentence (string, verbatim) or client copy with markup (R1-5's italic permission name). */
  body: ReactNode;
}

export interface PersonDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** The served `display_name`. */
  name: string;
  busy: boolean;
  notice: PersonDialogNotice | null;
  onConfirm: () => void;
  /** The page's `<h1>` (4b-4 / RED M-7): focus goes there on close when the opener no longer exists. */
  fallbackFocusRef: RefObject<HTMLElement | null>;
}

/**
 * §2.2 "Delete {name}?" — both body paragraphs describe the dialog (R4-4 L-6); initial focus is Cancel (danger dialog);
 * a refusal renders inside, its heading takes focus and the dialog stays open (4b-4); it closes only on success or
 * Cancel/Esc (none while busy), which the caller decides through `open`.
 */
export function DeletePersonDialog({ open, onOpenChange, name, busy, notice, onConfirm, fallbackFocusRef }: PersonDialogProps) {
  const cancel = useRef<HTMLButtonElement>(null);
  const heading = useRef<HTMLParagraphElement>(null);
  return (
    <Dialog
      open={open}
      onOpenChange={onOpenChange}
      tone="danger"
      title={DELETE_PERSON.title(name)}
      description={
        <>
          <p>{DELETE_PERSON.body1(name)}</p>
          <p>{DELETE_PERSON.body2}</p>
        </>
      }
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
          {DELETE_PERSON.cancel}
        </Button>
      )}
      <Button variant="destructive" loading={busy} onClick={onConfirm}>
        {DELETE_PERSON.confirm}
      </Button>
    </Dialog>
  );
}
