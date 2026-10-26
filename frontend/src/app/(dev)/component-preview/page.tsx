import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { ComponentPreview } from '@/components/preview/ComponentPreview';

export const metadata: Metadata = { title: 'Component preview' };

/** Development-only: a component preview with illustrative props, not a product screen. Absent from production builds. */
export default function Page() {
  if (process.env.NODE_ENV === 'production') notFound();
  return <ComponentPreview />;
}
