import { render } from '@testing-library/react';
import axe from 'axe-core';
import { describe, expect, it } from 'vitest';
import { ComponentPreview } from '@/components/preview/ComponentPreview';

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
