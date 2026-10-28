import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Chip } from '@/components/ui/Chip';

describe('Chip', () => {
  it('carries its meaning in words; the icon is hidden from assistive tech', () => {
    render(
      <Chip tone="warning" icon={<svg data-testid="i" />}>
        Expired
      </Chip>,
    );
    expect(screen.getByText('Expired')).toBeInTheDocument();
    expect(screen.getByTestId('i').parentElement).toHaveAttribute('aria-hidden', 'true');
  });
});
