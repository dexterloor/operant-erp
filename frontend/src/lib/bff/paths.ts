import { operation, type SurfaceOperation } from './surface';

// The ONE client-side builder of /bff/api paths (b3b-design.md §3 rule R-A6; RED M-7). Every path parameter must be a
// canonical lowercase UUID before it is encoded, so a bad id fails before any request; `/api/v1/auth/*` never maps.

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;
const API_PREFIX = '/api/v1/';

/** The browser path of one surface operation. Throws on a missing, extra or malformed parameter. */
export function bffPathFor(op: SurfaceOperation, params: Readonly<Record<string, string>>): string {
  if (!op.template.startsWith(API_PREFIX) || op.template.startsWith(`${API_PREFIX}auth/`)) throw new Error(`${op.template} is not a proxied path.`);
  const given = Object.keys(params);
  if (given.length !== op.pathParams.length || given.some((k) => !op.pathParams.includes(k))) throw new Error(`Wrong parameters for ${op.template}.`);
  let path = op.template;
  for (const name of op.pathParams) {
    const v = params[name];
    if (typeof v !== 'string' || !UUID.test(v)) throw new Error(`Parameter ${name} is not a record id.`);
    path = path.replace(`{${name}}`, encodeURIComponent(v));
  }
  return `/bff/api/${path.slice(API_PREFIX.length)}`;
}

/** The browser path of a wired operation, by operationId. */
export function bffPath(operationId: string, params: Readonly<Record<string, string>> = {}): string {
  return bffPathFor(operation(operationId), params);
}
