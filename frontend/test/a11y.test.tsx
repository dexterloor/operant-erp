import { render } from '@testing-library/react';
import axe from 'axe-core';
import { describe, expect, it } from 'vitest';
import { ComponentPreview } from '@/components/preview/ComponentPreview';
import { Checkbox } from '@/components/ui/Checkbox';
import { DateTime } from '@/components/ui/DateTime';
import { Dialog } from '@/components/ui/Dialog';
import { TooltipProvider } from '@/components/ui/Tooltip';

// axe on the component preview in jsdom. Two limits of jsdom, handled explicitly rather than by switching rules off:
//  - no layout or computed colours → colour-contrast is disabled here; contrast is computed from the tokens instead
//    (scripts/contrast.mjs, test/tokens.test.ts);
//  - no CSS media queries → all three navigation slots would be "visible" at once. The frame renders the 248 px
//    sidebar, the 64 px rail and the menu button and shows exactly one per width class with CSS. Each width class is
//    simulated by hiding the other slots, and axe runs once per class.
const CLASSES = { 'desktop ≥ 1280': 'sidebar', 'rail 768–1279': 'rail', 'menu < 768': 'menu' } as const;

describe('axe — component preview', () => {
  for (const [name, visible] of Object.entries(CLASSES)) {
    it(`has no violations at ${name}`, async () => {
      const { container } = render(<ComponentPreview />);
      const slots = container.querySelectorAll<HTMLElement>('[data-shell-slot]');
      expect(slots).toHaveLength(3);
      slots.forEach((el) => {
        el.style.display = el.dataset.shellSlot === visible ? '' : 'none';
      });
      const result = await axe.run(container, { rules: { 'color-contrast': { enabled: false } } });
      const summary = result.violations.map((v) => `${v.id}: ${v.nodes.length} × ${v.help}`);
      expect(summary).toEqual([]);
    });
  }
});

// B-4 step 1: the open Dialog is portalled out of the preview's container, so it gets its own axe run.
describe('axe — B-4 primitives', () => {
  it('an open Dialog with an unavailable Checkbox and a DateTime has no violations', async () => {
    const ref = { current: null as HTMLElement | null };
    render(
      <TooltipProvider>
        <h1 tabIndex={-1}>Page</h1>
        <Dialog open onOpenChange={() => undefined} title="Archive 3 items?" description={<p>You can restore them later.</p>} initialFocusRef={ref} fallbackFocusRef={ref}>
          <Checkbox label="Also archive lines" checked="mixed" onCheckedChange={() => undefined} unavailable describedBy="why" />
          <p id="why">Reason text.</p>
          <DateTime value="2026-10-22T06:05:00Z" form="date-time" timeZone="UTC" />
        </Dialog>
      </TooltipProvider>,
    );
    const result = await axe.run(document.body, { rules: { 'color-contrast': { enabled: false } } });
    expect(result.violations.map((v) => `${v.id}: ${v.nodes.length} × ${v.help}`)).toEqual([]);
  });
});
