import { StrictMode, useEffect, useRef, useState } from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { Banner } from '@/components/ui/Banner';
import { Button } from '@/components/ui/Button';
import { Dialog, type DialogRefusal } from '@/components/ui/Dialog';

// A props-only page shape: an <h1> owned by the route (the fallback), an opener that disappears after success (Delete
// person), and a dialog whose action is refused once, then succeeds (R4-4 L-6, 4b-4, RED M-7).
function Page({ refuseFirst = false }: { refuseFirst?: boolean }) {
  const h1 = useRef<HTMLHeadingElement>(null);
  const cancel = useRef<HTMLButtonElement>(null);
  const refusalHeading = useRef<HTMLParagraphElement>(null);
  const [open, setOpen] = useState(false);
  const [deleted, setDeleted] = useState(false);
  const [refusal, setRefusal] = useState<DialogRefusal | null>(null);
  const [tries, setTries] = useState(0);
  return (
    <main>
      <h1 ref={h1} tabIndex={-1}>
        Ana Reyes
      </h1>
      {deleted ? null : <Button onClick={() => setOpen(true)}>Delete person</Button>}
      <Button>Elsewhere</Button>
      <Dialog
        open={open}
        onOpenChange={(next) => {
          setOpen(next);
          if (!next) setRefusal(null);
        }}
        title="Delete Ana Reyes?"
        description={
          <>
            <p>They can no longer sign in.</p>
            <p>You can restore them later.</p>
          </>
        }
        tone="danger"
        initialFocusRef={cancel}
        fallbackFocusRef={h1}
        refusal={refusal}
      >
        <Button ref={cancel} onClick={() => setOpen(false)}>
          Cancel
        </Button>
        <Button
          variant="destructive"
          onClick={() => {
            setTries(tries + 1);
            if (refuseFirst && tries === 0) {
              setRefusal({
                id: 'r1',
                headingRef: refusalHeading,
                content: (
                  <Banner tone="danger" heading="Not deleted" ref={refusalHeading}>
                    <p>Server sentence.</p>
                  </Banner>
                ),
              });
              return;
            }
            setDeleted(true);
            setOpen(false);
          }}
        >
          Delete person
        </Button>
      </Dialog>
    </main>
  );
}

describe('Dialog (R4-4 L-6, 4b-4, RED M-7)', () => {
  it('names and describes itself; initial focus on the caller-chosen control', async () => {
    const user = userEvent.setup();
    render(<Page />);
    await user.click(screen.getByRole('button', { name: 'Delete person' }));
    const dialog = screen.getByRole('dialog', { name: 'Delete Ana Reyes?' });
    expect(dialog).toHaveAccessibleDescription('They can no longer sign in. You can restore them later.');
    await waitFor(() => expect(screen.getByRole('button', { name: 'Cancel' })).toHaveFocus());
  });

  it('Esc closes and returns focus to the opener', async () => {
    const user = userEvent.setup();
    render(<Page />);
    const opener = screen.getByRole('button', { name: 'Delete person' });
    await user.click(opener);
    await user.keyboard('{Escape}');
    expect(screen.queryByRole('dialog')).toBeNull();
    await waitFor(() => expect(opener).toHaveFocus());
  });

  it('when the opener is gone after success, focus goes to the fallback (the route h1)', async () => {
    const user = userEvent.setup();
    render(<Page />);
    await user.click(screen.getByRole('button', { name: 'Delete person' }));
    await user.click(screen.getAllByRole('button', { name: 'Delete person' }).at(-1)!);
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Delete person' })).toBeNull();
    await waitFor(() => expect(screen.getByRole('heading', { level: 1, name: 'Ana Reyes' })).toHaveFocus());
  });

  it('a refusal renders inside the open dialog, takes focus to its heading, and the trap holds', async () => {
    const user = userEvent.setup();
    render(<Page refuseFirst />);
    await user.click(screen.getByRole('button', { name: 'Delete person' }));
    const dialog = screen.getByRole('dialog');
    await user.click(screen.getAllByRole('button', { name: 'Delete person' }).at(-1)!);
    expect(screen.getByRole('dialog')).toBe(dialog);
    const heading = screen.getByText('Not deleted');
    expect(dialog).toContainElement(heading);
    await waitFor(() => expect(heading).toHaveFocus());
    for (let i = 0; i < 4; i += 1) {
      await user.tab();
      expect(dialog).toContainElement(document.activeElement as HTMLElement);
    }
  });

  it('under StrictMode: one dialog, same focus rules', async () => {
    const user = userEvent.setup();
    render(
      <StrictMode>
        <Page />
      </StrictMode>,
    );
    const opener = screen.getByRole('button', { name: 'Delete person' });
    await user.click(opener);
    expect(screen.getAllByRole('dialog')).toHaveLength(1);
    await waitFor(() => expect(screen.getByRole('button', { name: 'Cancel' })).toHaveFocus());
    await user.keyboard('{Escape}');
    await waitFor(() => expect(opener).toHaveFocus());
  });
});

// The person-page flake (b3b-followup "Flake"): after a delete whose re-read failed, the page's region error takes focus
// to its heading — but Radix returns focus to the opener on ITS OWN timer after the dialog unmounts, so whichever of the
// two ran last owned focus. Here the page moves focus in the very commit that closes the dialog, i.e. always before
// Radix's timer: the dialog must leave that focus where the page put it, and return focus only when nothing else has it.
/** As the page's RegionError does (useFocusOnMount): focus in a passive effect, after the commit. */
function FocusedOnMount() {
  const ref = useRef<HTMLHeadingElement>(null);
  useEffect(() => ref.current?.focus(), []);
  return (
    <h2 ref={ref} tabIndex={-1}>
      Something went wrong on our side.
    </h2>
  );
}

function PageThatTakesFocusOnClose() {
  const h1 = useRef<HTMLHeadingElement>(null);
  const cancel = useRef<HTMLButtonElement>(null);
  const [open, setOpen] = useState(false);
  const [failed, setFailed] = useState(false);
  return (
    <main>
      <h1 ref={h1} tabIndex={-1}>
        Ana Reyes
      </h1>
      <Button onClick={() => setOpen(true)}>Delete person</Button>
      {failed ? (
        <FocusedOnMount />
      ) : null}
      <Dialog open={open} onOpenChange={setOpen} title="Delete Ana Reyes?" description={<p>They will lose access.</p>} initialFocusRef={cancel} fallbackFocusRef={h1}>
        <Button ref={cancel} onClick={() => setOpen(false)}>
          Cancel
        </Button>
        <Button
          onClick={() => {
            setOpen(false);
            setFailed(true);
          }}
        >
          Delete person
        </Button>
      </Dialog>
    </main>
  );
}

describe('Dialog — close focus never takes focus back from the page', () => {
  it('a heading the page focused as the dialog closed keeps focus after the close timers have run', async () => {
    const user = userEvent.setup();
    render(<PageThatTakesFocusOnClose />);
    await user.click(screen.getByRole('button', { name: 'Delete person' }));
    const dialog = await screen.findByRole('dialog');
    await user.click(within(dialog).getByRole('button', { name: 'Delete person' }));
    const heading = await screen.findByRole('heading', { name: 'Something went wrong on our side.' });
    await new Promise((r) => setTimeout(r, 50));
    expect(heading).toHaveFocus();
  });

  it('with nothing else focused, closing still returns focus to the opener', async () => {
    const user = userEvent.setup();
    render(<PageThatTakesFocusOnClose />);
    await user.click(screen.getByRole('button', { name: 'Delete person' }));
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Cancel' }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Delete person' })).toHaveFocus());
  });
});
