import { gateCode, viewerMay } from '../bff/surface';

// The gate for stock rows (stock-st2-plan.md §5; G-47 re-put, g47-ruling.md R-3; UX `stock-st2-ux-copy.md` §B-6). A stock
// row does not say whether its location can be picked, so rows are shown only to a viewer who may also read the
// location lists and the warehouse (the marker's three facts). The gates come from the contract, never typed, and each is
// spelled out as its own call (G-47's pin, rule (c1)). Imported only by server pages: no client file reaches this file
// (Revision 12 R12-4), and a states file receives `missing` as a prop.

type Codes = ReadonlySet<string> | readonly string[];

/** True only when the viewer may read the stock rows AND every marker read (the location lists and both warehouse reads). */
export function stockRowsVisible(codes: Codes): boolean {
  return viewerMay('ListStockLevels', codes) && viewerMay('ListStorageLocations', codes) && viewerMay('GetWarehouse', codes) && viewerMay('ListWarehouses', codes);
}

/**
 * The permission codes the viewer lacks for the marker reads, read from the contract: the gates of the location lists, the
 * warehouse read and the warehouse list, in that order, without repeats. Empty when every marker read is allowed.
 */
export function missingMarkerCodes(codes: Codes): string[] {
  const missing: string[] = [];
  for (const operationId of ['ListStorageLocations', 'GetWarehouse', 'ListWarehouses']) {
    if (viewerMay(operationId, codes)) continue;
    const code = gateCode(operationId);
    if (code !== null && !missing.includes(code)) missing.push(code);
  }
  return missing;
}
