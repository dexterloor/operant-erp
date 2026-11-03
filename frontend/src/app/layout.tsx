import type { Metadata } from 'next';
import type { ReactNode } from 'react';
import '@/styles/globals.css';
import { ThemeBootScript } from '@/components/theme/ThemeBootScript';
import { DocumentArrival } from '@/components/shell/DocumentArrival';
import { APP_TITLE } from '@/copy/shell';
import { fontVariables } from './fonts';

export const metadata: Metadata = {
  title: { ...APP_TITLE },
};

// Reads nothing that can fail, so `src/app/error.tsx` covers every throw below it. The day this layout reads data, add
// `global-error.tsx` (b3b-followup C-2).
export default function RootLayout({ children }: { children: ReactNode }) {
  // data-theme="light" is the server default (D4); the boot script replaces it before first paint with the stored
  // choice, hence suppressHydrationWarning on <html> only.
  return (
    <html lang="en" data-theme="light" className={fontVariables} suppressHydrationWarning>
      <head>
        <ThemeBootScript />
      </head>
      <body>
        {children}
        <DocumentArrival />
      </body>
    </html>
  );
}
