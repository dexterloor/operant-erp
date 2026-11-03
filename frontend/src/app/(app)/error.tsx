'use client';

import { PageError } from '@/components/shell/PageError';

/**
 * A page inside the app frame threw while loading (b3b-followup C-2): our page in the content area, the frame and its
 * menu stay. The frame already provides `<main>`, so this adds none. The error object is deliberately not read (ST-3).
 */
export default function AppError() {
  return <PageError />;
}
