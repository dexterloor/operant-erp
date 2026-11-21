import type { StockLevel } from '../api/stock';

// The row marker for Stock on hand (stock-st2-plan.md §3.3; UX `stock-st2-ux-copy.md` §B-5; G-47 re-put, g47-ruling.md
// R-3). PURE: it imports only stock types, calls nothing and reads nothing (G-47's pin, rule (c2)). A stock row does not
// say whether its location can be picked, so the page reads the location's kind and archived state, and the warehouse's
// archived state, from the location and warehouse lists, and this function turns those facts into badges. It FAILS
// CLOSED: no badge is a claim, made only when all three facts loaded and exactly one live location of a pickable kind
// matched; anything else adds Status unknown. Known badges are never hidden behind Status unknown (UX rule 1).

/** One read's outcome: its value, or that it did not load. */
export type Loaded<T> = { readonly kind: 'loaded'; readonly value: T } | { readonly kind: 'failed' };

/** What the marker needs of a location: its id and its served kind (from the location lists). */
export interface LocationFact {
  readonly id: string;
  readonly kind: string;
}

/** The three facts of one warehouse a row depends on. */
export interface WarehouseMarkerInputs {
  /** The warehouse these reads were made for. A map entry whose id differs from its key is not trusted. */
  readonly warehouseId: string;
  /** One location's scope: the warehouse's own read. One item's scope: both warehouse lists (loaded only when both did). */
  readonly archivedAt: Loaded<string | null>;
  /** The warehouse's live locations. */
  readonly live: Loaded<readonly LocationFact[]>;
  /** The warehouse's archived locations. */
  readonly archived: Loaded<readonly LocationFact[]>;
}

/** The facts, keyed by warehouse id (a row is looked up only under its own warehouse). */
export type MarkerInputs = ReadonlyMap<string, WarehouseMarkerInputs>;

/** The badges of one place. Every flag false is the one "no badge" result. */
export interface Marker {
  readonly quarantine: boolean;
  readonly locationArchived: boolean;
  readonly warehouseArchived: boolean;
  readonly unknown: boolean;
}

/** The served kinds a location may have and still be picked from. A served kind outside these and `quarantine` is unknown. */
export const PICKABLE_KINDS: readonly string[] = ['bin', 'shelf', 'zone', 'staging'];

const QUARANTINE = 'quarantine';

/** The place a marker is computed for: a row, or the one location a scope names. */
export interface MarkedPlace {
  readonly warehouse: { readonly id: string };
  readonly storage_location: { readonly id: string };
}

/**
 * The badges for one place (plan §3.3): W = its warehouse, L = its location, e = the facts for W.
 * - no facts for W (or facts made for another warehouse) → Status unknown, nothing else;
 * - warehouse archived = its state loaded and set;
 * - location archived = L in the archived list and not in the live one;
 * - quarantine = L found, and every entry for it has kind `quarantine`;
 * - Status unknown when any fact failed, L is not found exactly once across both lists, a kind is not served-known, or
 *   two entries for L disagree on kind.
 */
export function stockRowMarker(place: MarkedPlace, inputs: MarkerInputs): Marker {
  const w = place.warehouse.id;
  const l = place.storage_location.id;
  const e = inputs.get(w);
  if (e === undefined || e.warehouseId !== w) return { quarantine: false, locationArchived: false, warehouseArchived: false, unknown: true };
  const liveHits = e.live.kind === 'loaded' ? e.live.value.filter((f) => f.id === l) : [];
  const archivedHits = e.archived.kind === 'loaded' ? e.archived.value.filter((f) => f.id === l) : [];
  const hits = [...liveHits, ...archivedHits];
  const warehouseArchived = e.archivedAt.kind === 'loaded' && e.archivedAt.value !== null;
  const locationArchived = archivedHits.length >= 1 && liveHits.length === 0;
  const quarantine = hits.length >= 1 && hits.every((f) => f.kind === QUARANTINE);
  const knownKind = (k: string) => k === QUARANTINE || PICKABLE_KINDS.includes(k);
  const unknown =
    e.archivedAt.kind === 'failed' ||
    e.live.kind === 'failed' ||
    e.archived.kind === 'failed' ||
    hits.length !== 1 ||
    hits.some((f) => !knownKind(f.kind)) ||
    hits.some((f) => f.kind !== hits[0]!.kind);
  return { quarantine, locationArchived, warehouseArchived, unknown };
}

/** A row with its marker: the client view renders only these, so no row reaches the screen without one. */
export interface MarkedRow {
  readonly row: StockLevel;
  readonly marker: Marker;
}
