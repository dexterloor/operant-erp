import { Bricolage_Grotesque, JetBrains_Mono, Manrope } from 'next/font/google';

// Self-hosted at build time by next/font: the files are downloaded once during `next build` and served from this app,
// so the browser never requests Google Fonts at runtime. Each face is exposed as --font-face-<role>, which the
// generated tokens.css puts at the head of --font-sans / --font-display / --font-mono.
export const sans = Manrope({ subsets: ['latin'], variable: '--font-face-sans', display: 'swap' });
export const display = Bricolage_Grotesque({ subsets: ['latin'], variable: '--font-face-display', display: 'swap' });
export const mono = JetBrains_Mono({ subsets: ['latin'], variable: '--font-face-mono', display: 'swap' });

export const fontVariables = [sans.variable, display.variable, mono.variable].join(' ');
