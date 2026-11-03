import { THEME_BOOT_SCRIPT } from '@/lib/theme';

/** Rendered inside <head> by the root layout: runs before first paint, so the stored theme never flashes (D4, M-9 item 4). */
export function ThemeBootScript() {
  return <script id="erp-theme-boot" dangerouslySetInnerHTML={{ __html: THEME_BOOT_SCRIPT }} />;
}
