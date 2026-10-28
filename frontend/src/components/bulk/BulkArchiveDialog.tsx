'use client';

import { useRef, type ReactNode, type RefObject } from 'react';
import { BULK_DELETE_DIALOG, type BulkNouns } from '@/copy/bulk';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { Dialog } from '@/components/ui/Dialog';

/** A banner inside the open dialog: a refusal, a lost permission or an interrupted run. A new `id` moves focus to it. */
export interface BulkDialogNotice {
  id: number;
  heading: string;
  /** The server's sentence (verbatim) or client copy. */
  body: ReactNode;
}

export interface BulkArchiveDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  count: number;
  nouns: BulkNouns;
  busy: boolean;
  notice: BulkDialogNotice | null;
  onConfirm: () => void;
  /** The list's `<h1>` (4b-4, RED M-7): focus goes there on close when the opener (the bar's Delete) is gone. */
  fallbackFocusRef: RefObject<HTMLElement | null>;
}

/**
 * The bulk Delete dialog (b4-owed-copy.md R1-10 rows 3–5; the file keeps its tree name, the act is Delete): it names the
 * count, never a list. Initial focus is **Cancel**, as every other Delete dialog (R1-10 row 5, overriding R4-4 L-6's
 * "Archive, focused"). A refusal or interruption renders inside, its heading takes focus and the dialog stays open
 * (4b-4); it closes only on success, Cancel or Esc (none while busy), which the caller decides
 * through `open`.
 */
export function BulkArchiveDialog({ open, onOpenChange, count, nouns, busy, notice, onConfirm, fallbackFocusRef }: BulkArchiveDialogProps) {
  const cancel = useRef<HTMLButtonElement>(null);
  const heading = useRef<HTMLParagraphElement>(null);
  return (
    <Dialog
      open={open}
      onOpenChange={onOpenChange}
      tone="danger"
      title={BULK_DELETE_DIALOG.title(count, nouns)}
      description={<p>{BULK_DELETE_DIALOG.body(nouns)}</p>}
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
          {BULK_DELETE_DIALOG.cancel}
        </Button>
      )}
      <Button variant="destructive" loading={busy} onClick={onConfirm}>
        {BULK_DELETE_DIALOG.confirm}
      </Button>
    </Dialog>
  );
}
