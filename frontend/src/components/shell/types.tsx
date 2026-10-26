import type { ComponentType, ReactNode } from 'react';

/** The app passes next/link here; tests and the preview use a plain anchor. Keeps shell components free of routing. */
export type LinkComponent = ComponentType<{
  href: string;
  className?: string;
  children?: ReactNode;
  'aria-current'?: 'page';
  'aria-label'?: string;
  onClick?: () => void;
}>;

export const PlainLink: LinkComponent = ({ href, children, ...rest }) => (
  <a href={href} {...rest}>
    {children}
  </a>
);
