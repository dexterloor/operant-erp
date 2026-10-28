import type { Metadata } from 'next';
import { notFound } from 'next/navigation';

export const metadata: Metadata = { title: 'Component preview' };

/**
 * Development-only: a component preview with illustrative props, not a product screen. Absent from production builds.
 * The preview is imported dynamically behind the build-time `NODE_ENV` check (RED L-2): a static import shipped its
 * client chunk — and the fixtures with shipped server sentences — in `.next/static` even though the page 404s.
 * `scripts/check-fixtures-absent.mjs` (run by `postbuild`) fails the build if the fixture marker reaches the bundle.
 */
export default async function Page() {
  const preview = process.env.NODE_ENV !== 'production' ? await import('@/components/preview/ComponentPreview') : null;
  if (!preview) notFound();
  return <preview.ComponentPreview />;
}
