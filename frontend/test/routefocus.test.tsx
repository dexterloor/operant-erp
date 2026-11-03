import { StrictMode } from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { RouteFocus } from '@/components/shell/RouteFocus';

describe('RouteFocus under StrictMode', () => {
  it('does not move focus on arrival even though effects run twice', () => {
    render(
      <StrictMode>
        <main id="main-content" tabIndex={-1}>
          <h1>Arrived</h1>
        </main>
        <RouteFocus routeKey="/a" />
      </StrictMode>,
    );
    expect(screen.getByRole('heading', { name: 'Arrived' })).not.toHaveFocus();
  });
});
