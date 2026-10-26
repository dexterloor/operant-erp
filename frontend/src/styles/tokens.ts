/**
 * ERP design tokens. GENERATED from tokens.json by scripts/build-tokens.mjs. Do not edit by hand: change tokens.json and run `npm run tokens`.
 * Design export, Revision 5 'Harbour' (2026-10-23), with the 2026-10-27 review fixes M-2, M-9, L-3.
 */

export type ThemeName = 'light' | 'dark';

/** D4: Light | Dark | Match system, stored under THEME_STORAGE_KEY, default light. */

export type ThemePreference = 'light' | 'dark' | 'system';

export const THEME_STORAGE_KEY = 'erp.theme';

export const THEME_DEFAULT: ThemePreference = 'light';

export type Tone = 'info' | 'success' | 'warning' | 'danger' | 'neutral' | 'violet';

export type ChipVariant = 'subtle' | 'solid' | 'outline';



export const color = {
  light: {
    "bg": {
      "canvas": "#F2F5FA",
      "surface": "#FFFFFF",
      "surface-raised": "#FFFFFF",
      "sunken": "#EAEFF6",
      "hover": "#EEF3FA",
      "pressed": "#E1E8F2",
      "disabled": "#E3E8F0",
      "overlay": "rgba(10, 20, 45, 0.50)"
    },
    "fg": {
      "default": "#121A2B",
      "muted": "#4D5870",
      "subtle": "#5A6680",
      "disabled": "#535E76",
      "on-accent": "#FFFFFF",
      "link": "#2B4BD6"
    },
    "border": {
      "default": "#DCE3EE",
      "strong": "#76829A",
      "focus": "#2B4BD6"
    },
    "accent": {
      "solid": "#2B4BD6",
      "hover": "#2340B8",
      "pressed": "#1C339A",
      "subtle-bg": "#E6EBFC",
      "subtle-fg": "#2338A8"
    },
    "info": {
      "solid": "#0E6A85",
      "subtle-bg": "#DEF1F7",
      "subtle-fg": "#0F5368",
      "border": "#9FD0E0"
    },
    "success": {
      "solid": "#1A7A47",
      "subtle-bg": "#DFF3E7",
      "subtle-fg": "#16603A",
      "border": "#9DD2B2"
    },
    "warning": {
      "solid": "#A05300",
      "subtle-bg": "#FBEBCF",
      "subtle-fg": "#7C4500",
      "border": "#E8C27E"
    },
    "danger": {
      "solid": "#C2303A",
      "hover": "#A82830",
      "pressed": "#8F2028",
      "subtle-bg": "#FCE4E4",
      "subtle-fg": "#9C1F27",
      "border": "#EEA6AB"
    },
    "neutral": {
      "solid": "#4D5870",
      "subtle-bg": "#EBEFF5",
      "subtle-fg": "#465068",
      "border": "#C3CCDB"
    },
    "violet": {
      "solid": "#6A3BC0",
      "subtle-bg": "#F0E8FB",
      "subtle-fg": "#5B2BA0",
      "border": "#CDB6F0"
    },
    "qty": {
      "positive": "#1A7A47",
      "negative": "#B8283A"
    },
    "shell": {
      "bg": "linear-gradient(180deg, #13254A 0%, #0C1830 100%)",
      "solid": "#13254A",
      "edge": "#0C1830",
      "fg": "#E7EDF8",
      "muted": "#A6B4CC",
      "group": "#8E9DB8",
      "hover": "#1B2F58",
      "active-bg": "#22396A",
      "active-fg": "#FFFFFF",
      "indicator": "#38D3C1",
      "focus": "#38D3C1"
    },
    "brand": {
      "highlight": "#38D3C1",
      "mark": "linear-gradient(135deg, #38D3C1, #2B4BD6)",
      "wash": "linear-gradient(180deg, #E6EBFC 0%, rgba(230, 235, 252, 0) 100%)",
      "motif": "radial-gradient(circle at 30% 25%, #FFFFFF 0, #E6EBFC 45%, #C9D5FB 100%)",
      "motif-fg": "#2338A8"
    }
  },
  dark: {
    "bg": {
      "canvas": "#0A1120",
      "surface": "#101A2D",
      "surface-raised": "#16233B",
      "sunken": "#0C1526",
      "hover": "#15223A",
      "pressed": "#1C2B47",
      "disabled": "#1E2A40",
      "overlay": "rgba(2, 6, 15, 0.65)"
    },
    "fg": {
      "default": "#E7ECF5",
      "muted": "#AAB5C9",
      "subtle": "#94A0B6",
      "disabled": "#8E9AB0",
      "on-accent": "#0A1120",
      "link": "#8FA6FF"
    },
    "border": {
      "default": "#22304A",
      "strong": "#68768F",
      "focus": "#8FA6FF"
    },
    "accent": {
      "solid": "#8FA6FF",
      "hover": "#A9BAFF",
      "pressed": "#C2CEFF",
      "subtle-bg": "#1C2A55",
      "subtle-fg": "#BAC8FF"
    },
    "info": {
      "solid": "#6CC7E0",
      "subtle-bg": "#0D3240",
      "subtle-fg": "#8FD6EA",
      "border": "#1E4D5E"
    },
    "success": {
      "solid": "#5CCB8E",
      "subtle-bg": "#0F3222",
      "subtle-fg": "#8FDDB0",
      "border": "#1F5239"
    },
    "warning": {
      "solid": "#E8A548",
      "subtle-bg": "#3A2A0B",
      "subtle-fg": "#F2C378",
      "border": "#64491A"
    },
    "danger": {
      "solid": "#FF8F98",
      "hover": "#FFA6AD",
      "pressed": "#FFBDC2",
      "subtle-bg": "#3D151B",
      "subtle-fg": "#FFA3AA",
      "border": "#6B2A32"
    },
    "neutral": {
      "solid": "#A6B1C4",
      "subtle-bg": "#1A2438",
      "subtle-fg": "#C3CCDB",
      "border": "#33415C"
    },
    "violet": {
      "solid": "#B79BFF",
      "subtle-bg": "#2B1D4A",
      "subtle-fg": "#CDB4F7",
      "border": "#4A3575"
    },
    "qty": {
      "positive": "#6FD69E",
      "negative": "#FF8F98"
    },
    "shell": {
      "bg": "linear-gradient(180deg, #0B1733 0%, #060C18 100%)",
      "solid": "#0B1733",
      "edge": "#1A2742",
      "fg": "#E7EDF8",
      "muted": "#A6B4CC",
      "group": "#8E9DB8",
      "hover": "#15244A",
      "active-bg": "#1B2C52",
      "active-fg": "#FFFFFF",
      "indicator": "#38D3C1",
      "focus": "#38D3C1"
    },
    "brand": {
      "highlight": "#38D3C1",
      "mark": "linear-gradient(135deg, #38D3C1, #8FA6FF)",
      "wash": "linear-gradient(180deg, #1C2A55 0%, rgba(28, 42, 85, 0) 100%)",
      "motif": "radial-gradient(circle at 30% 25%, #3A4E92 0, #1C2A55 70%)",
      "motif-fg": "#BAC8FF"
    }
  }
} as const;

/** Theme-aware CSS variable references, e.g. vars.bg.canvas === 'var(--bg-canvas)'. */
export const vars = {
  "bg": {
    "canvas": "var(--bg-canvas)",
    "surface": "var(--bg-surface)",
    "surface-raised": "var(--bg-surface-raised)",
    "sunken": "var(--bg-sunken)",
    "hover": "var(--bg-hover)",
    "pressed": "var(--bg-pressed)",
    "disabled": "var(--bg-disabled)",
    "overlay": "var(--bg-overlay)"
  },
  "fg": {
    "default": "var(--fg-default)",
    "muted": "var(--fg-muted)",
    "subtle": "var(--fg-subtle)",
    "disabled": "var(--fg-disabled)",
    "on-accent": "var(--fg-on-accent)",
    "link": "var(--fg-link)"
  },
  "border": {
    "default": "var(--border-default)",
    "strong": "var(--border-strong)",
    "focus": "var(--border-focus)"
  },
  "accent": {
    "solid": "var(--accent-solid)",
    "hover": "var(--accent-hover)",
    "pressed": "var(--accent-pressed)",
    "subtle-bg": "var(--accent-subtle-bg)",
    "subtle-fg": "var(--accent-subtle-fg)"
  },
  "info": {
    "solid": "var(--info-solid)",
    "subtle-bg": "var(--info-subtle-bg)",
    "subtle-fg": "var(--info-subtle-fg)",
    "border": "var(--info-border)"
  },
  "success": {
    "solid": "var(--success-solid)",
    "subtle-bg": "var(--success-subtle-bg)",
    "subtle-fg": "var(--success-subtle-fg)",
    "border": "var(--success-border)"
  },
  "warning": {
    "solid": "var(--warning-solid)",
    "subtle-bg": "var(--warning-subtle-bg)",
    "subtle-fg": "var(--warning-subtle-fg)",
    "border": "var(--warning-border)"
  },
  "danger": {
    "solid": "var(--danger-solid)",
    "hover": "var(--danger-hover)",
    "pressed": "var(--danger-pressed)",
    "subtle-bg": "var(--danger-subtle-bg)",
    "subtle-fg": "var(--danger-subtle-fg)",
    "border": "var(--danger-border)"
  },
  "neutral": {
    "solid": "var(--neutral-solid)",
    "subtle-bg": "var(--neutral-subtle-bg)",
    "subtle-fg": "var(--neutral-subtle-fg)",
    "border": "var(--neutral-border)"
  },
  "violet": {
    "solid": "var(--violet-solid)",
    "subtle-bg": "var(--violet-subtle-bg)",
    "subtle-fg": "var(--violet-subtle-fg)",
    "border": "var(--violet-border)"
  },
  "qty": {
    "positive": "var(--qty-positive)",
    "negative": "var(--qty-negative)"
  },
  "shell": {
    "bg": "var(--shell-bg)",
    "solid": "var(--shell-solid)",
    "edge": "var(--shell-edge)",
    "fg": "var(--shell-fg)",
    "muted": "var(--shell-muted)",
    "group": "var(--shell-group)",
    "hover": "var(--shell-hover)",
    "active-bg": "var(--shell-active-bg)",
    "active-fg": "var(--shell-active-fg)",
    "indicator": "var(--shell-indicator)",
    "focus": "var(--shell-focus)"
  },
  "brand": {
    "highlight": "var(--brand-highlight)",
    "mark": "var(--brand-mark)",
    "wash": "var(--brand-wash)",
    "motif": "var(--brand-motif)",
    "motif-fg": "var(--brand-motif-fg)"
  }
} as const;

export const font = {
  "sans": "'Manrope', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif",
  "display": "'Bricolage Grotesque', 'Manrope', ui-sans-serif, system-ui, sans-serif",
  "mono": "'JetBrains Mono', ui-monospace, 'SF Mono', Menlo, Consolas, monospace",
  "numeric": "tabular-nums",
  "weight": {
    "regular": 400,
    "medium": 500,
    "semibold": 600,
    "bold": 700
  }
} as const;

export const type = {
  "display": {
    "size": 30,
    "line": 36,
    "weight": 700,
    "tracking": "-0.02em",
    "use": "Overview headline figure",
    "family": "display"
  },
  "h1": {
    "size": 24,
    "line": 32,
    "weight": 700,
    "tracking": "-0.02em",
    "use": "Page title",
    "family": "display"
  },
  "h2": {
    "size": 18,
    "line": 28,
    "weight": 700,
    "tracking": "-0.01em",
    "use": "Section heading",
    "family": "display"
  },
  "h3": {
    "size": 15,
    "line": 24,
    "weight": 600,
    "tracking": "0",
    "use": "Subsection heading"
  },
  "body": {
    "size": 14,
    "line": 20,
    "weight": 400,
    "tracking": "0",
    "use": "Default text, Comfortable table cells"
  },
  "body-sm": {
    "size": 13,
    "line": 18,
    "weight": 400,
    "tracking": "0",
    "use": "Compact table cells"
  },
  "caption": {
    "size": 12,
    "line": 16,
    "weight": 400,
    "tracking": "0",
    "use": "Field hints, meta, timestamps"
  },
  "overline": {
    "size": 11,
    "line": 16,
    "weight": 500,
    "tracking": "0.04em",
    "transform": "uppercase",
    "use": "Table headers, sidebar group labels"
  },
  "label": {
    "size": 14,
    "line": 20,
    "weight": 500,
    "tracking": "0",
    "use": "Form labels, button labels"
  }
} as const;

export const space = [
  0,
  2,
  4,
  6,
  8,
  12,
  16,
  20,
  24,
  32,
  40,
  48,
  64
] as const;

export const radius = {
  "none": 0,
  "sm": 6,
  "md": 8,
  "lg": 12,
  "chip": 9999,
  "full": 9999
} as const;

export const elevation = {
  "light": {
    "e0": "none",
    "e1": "0 1px 2px rgba(20, 40, 90, 0.06), 0 6px 16px -8px rgba(20, 40, 90, 0.14)",
    "e2": "0 2px 4px rgba(20, 40, 90, 0.06), 0 14px 30px -12px rgba(20, 40, 90, 0.30)",
    "e3": "0 8px 16px -8px rgba(20, 40, 90, 0.20), 0 36px 72px -24px rgba(20, 40, 90, 0.48)",
    "e4": "-12px 0 32px -8px rgba(20, 40, 90, 0.22)"
  },
  "dark": {
    "e0": "none",
    "e1": "0 1px 2px rgba(0, 0, 0, 0.40), 0 6px 16px -8px rgba(0, 0, 0, 0.50)",
    "e2": "0 2px 4px rgba(0, 0, 0, 0.40), 0 14px 30px -12px rgba(0, 0, 0, 0.65)",
    "e3": "0 8px 16px -8px rgba(0, 0, 0, 0.50), 0 36px 72px -24px rgba(0, 0, 0, 0.80)",
    "e4": "-12px 0 32px -8px rgba(0, 0, 0, 0.60)"
  },
  "spec": {
    "e0": {
      "surface": "bg.canvas",
      "border": false,
      "use": "Page canvas, flat content"
    },
    "e1": {
      "surface": "bg.surface",
      "border": true,
      "use": "Cards, section panels, tables (border + soft tinted shadow)"
    },
    "e2": {
      "surface": "bg.surface-raised",
      "border": true,
      "use": "Popovers, menus, combobox lists, tooltips"
    },
    "e3": {
      "surface": "bg.surface-raised",
      "border": true,
      "use": "Dialogs"
    },
    "e4": {
      "surface": "bg.surface-raised",
      "border": true,
      "use": "Slide-over (Ask, FUTURE)"
    }
  }
} as const;

export const zIndex = {
  "base": 0,
  "sticky": 10,
  "sidebar": 20,
  "dropdown": 30,
  "slideover": 40,
  "dialog": 50,
  "toast": 60,
  "tooltip": 70
} as const;

export const size = {
  "control-sm": 28,
  "control-md": 36,
  "control-lg": 40,
  "row-comfortable": 44,
  "row-compact": 32,
  "icon-sm": 16,
  "icon-md": 20,
  "icon-stroke": 1.5,
  "target-min": 24,
  "focus-ring": 2,
  "focus-offset": 2,
  "sidebar": 248,
  "rail": 64,
  "topbar": 56,
  "content-max": 1440,
  "gutter": 24,
  "slideover": 480
} as const;

export const motion = {
  "duration": {
    "instant": 80,
    "fast": 120,
    "base": 180,
    "slow": 240,
    "deliberate": 320
  },
  "exit": {
    "instant": 60,
    "fast": 80,
    "base": 120,
    "slow": 170,
    "deliberate": 220
  },
  "special": {
    "reduced": 100,
    "line-collapse": 150,
    "strike-draw": 200,
    "highlight": 1200,
    "shimmer": 1600,
    "spin": 800
  },
  "ease": {
    "standard": "cubic-bezier(0.2, 0, 0, 1)",
    "enter": "cubic-bezier(0, 0, 0, 1)",
    "exit": "cubic-bezier(0.3, 0, 1, 1)",
    "linear": "linear",
    "emphasized": "cubic-bezier(0.2, 0.9, 0.3, 1.1)"
  },
  "distance": {
    "settle": 4,
    "toast": 8
  },
  "scale": {
    "press": 0.98,
    "dialog": 0.96,
    "menu": 0.98,
    "lift": -1
  }
} as const;

/** Values used under prefers-reduced-motion: reduce. */
export const reducedMotion = {
  "crossFade": 100,
  "maxDuration": 100,
  "distance": 0,
  "scale": 1,
  "smoothScroll": false,
  "shimmer": false,
  "spinner": false
} as const;

export const interaction = {
  "debounce-search": 250,
  "toast-dismiss": 8000
} as const;

export type StatusChip = { label: string; tone: Tone; variant: ChipVariant; icon?: string; spin?: boolean };

/** §4.2 status-to-colour map, keyed by wire value. */
export const statusChip = {
  "purchase_order": {
    "draft": {
      "label": "Draft",
      "tone": "neutral",
      "variant": "subtle"
    },
    "ordered": {
      "label": "Ordered",
      "tone": "info",
      "variant": "subtle"
    },
    "partially_received": {
      "label": "Partly received",
      "tone": "warning",
      "variant": "subtle"
    },
    "received": {
      "label": "Received",
      "tone": "success",
      "variant": "subtle"
    },
    "cancelled": {
      "label": "Cancelled",
      "tone": "neutral",
      "variant": "outline"
    }
  },
  "sales_order": {
    "draft": {
      "label": "Draft",
      "tone": "neutral",
      "variant": "subtle"
    },
    "confirmed": {
      "label": "Confirmed",
      "tone": "info",
      "variant": "subtle"
    },
    "partially_fulfilled": {
      "label": "Partly fulfilled",
      "tone": "warning",
      "variant": "subtle"
    },
    "fulfilled": {
      "label": "Fulfilled",
      "tone": "success",
      "variant": "subtle"
    },
    "cancelled": {
      "label": "Cancelled",
      "tone": "neutral",
      "variant": "outline"
    }
  },
  "invoice": {
    "issued": {
      "label": "Unpaid",
      "tone": "info",
      "variant": "subtle"
    },
    "overdue": {
      "label": "Overdue",
      "tone": "danger",
      "variant": "solid",
      "icon": "clock-alert"
    },
    "paid": {
      "label": "Paid",
      "tone": "success",
      "variant": "subtle"
    },
    "credited_in_full": {
      "label": "Credited in full",
      "tone": "violet",
      "variant": "subtle"
    },
    "void": {
      "label": "Void",
      "tone": "neutral",
      "variant": "outline"
    }
  },
  "role_proposal": {
    "pending": {
      "label": "Waiting for an owner",
      "tone": "warning",
      "variant": "subtle"
    },
    "pending_expired": {
      "label": "Expired — can't be confirmed",
      "tone": "neutral",
      "variant": "outline"
    },
    "confirmed": {
      "label": "Confirmed",
      "tone": "success",
      "variant": "subtle"
    },
    "rejected": {
      "label": "Rejected",
      "tone": "danger",
      "variant": "subtle"
    },
    "cancelled": {
      "label": "Withdrawn",
      "tone": "neutral",
      "variant": "subtle"
    },
    "lapsed": {
      "label": "Lapsed",
      "tone": "neutral",
      "variant": "subtle"
    }
  },
  "user": {
    "active": {
      "label": "Active",
      "tone": "success",
      "variant": "subtle"
    },
    "inactive": {
      "label": "Switched off",
      "tone": "neutral",
      "variant": "subtle"
    }
  },
  "archived": {
    "archived": {
      "label": "Archived",
      "tone": "neutral",
      "variant": "subtle"
    }
  },
  "async": {
    "processing": {
      "label": "Processing",
      "tone": "info",
      "variant": "subtle",
      "icon": "loader-circle",
      "spin": true
    }
  }
} as const satisfies Record<string, Record<string, StatusChip>>;

/** §4.6 Lucide icon map (kebab-case Lucide names). */
export const icon = {
  "Overview": "layout-dashboard",
  "Purchasing group": "shopping-cart",
  "Purchase order": "file-text",
  "Goods receipt": "package-check",
  "Supplier": "truck",
  "Item": "box",
  "Stock on Hand": "boxes",
  "Stock movement": "arrow-left-right",
  "Warehouse": "warehouse",
  "Location": "map-pin",
  "Sales order": "receipt",
  "Fulfilment / shipment": "package-open",
  "Invoice": "file-badge",
  "Credit note": "file-minus",
  "Sales return": "package-x",
  "Customer": "building-2",
  "Users": "users",
  "Roles": "shield",
  "Audit log": "scroll-text",
  "History": "history",
  "Settings": "settings",
  "Ask (AI, FUTURE)": "sparkles",
  "Count": "clipboard-check",
  "Move stock": "move-right",
  "Archive": "archive",
  "Restore": "archive-restore",
  "Export CSV": "download",
  "Filter": "filter",
  "Search": "search",
  "Newer": "chevron-left",
  "Older": "chevron-right",
  "Overdue": "clock-alert",
  "Separation-of-duty refusal": "split",
  "Margin warning": "triangle-alert",
  "Not found": "search-x",
  "Permission denied": "lock",
  "Conflict": "refresh-ccw",
  "Processing": "loader-circle"
} as const;

export const tokens = { color, vars, font, type, space, radius, elevation, zIndex, size, motion, reducedMotion, interaction, statusChip, icon } as const;

export default tokens;
