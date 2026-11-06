import { describe, expect, it } from 'vitest';
import type { Collection } from '@/lib/api/envelope';
import type { Permission, SodRowRef } from '@/lib/api/permissions';
import type { RoleGrantProposal, UserDisplayReference } from '@/lib/api/proposals';
import type { OfferedRole, Role, RoleConflict } from '@/lib/api/roles';
import type { RoleReference, User } from '@/lib/api/users';
import type { CreateStorageLocationInput, CreateWarehouseInput, StorageLocation, UpdateStorageLocationInput, UpdateWarehouseInput, Warehouse } from '@/lib/api/warehouses';
import { mirroredSchemas, wireFields } from '@/lib/bff/surface';

// C-1 (b3b §14): every hand-written wire type in lib/api has exactly the served property names. The literal is a
// `Record<keyof T, true>`, so typecheck fails when the type gains or loses a member the literal does not list, and this
// test fails when the served schema and the type disagree. No codegen dependency (b3b §4).

const keysOf =
  <T>() =>
  (r: Record<keyof T, true>): string[] =>
    Object.keys(r).sort();

const MIRRORED: Record<string, string[]> = {
  User: keysOf<User>()({ id: true, email: true, display_name: true, is_active: true, archived_at: true, roles: true, margin_exposure: true }),
  RoleReference: keysOf<RoleReference>()({ id: true, name: true }),
  // The item of `User.roles` itself, not only the component of the same name: the served description now types it as
  // `{id, name}` (it was a list of nullable strings before d95d475), so the person's roles are checked from the
  // description rather than taken from the backend source by hand.
  'User.roles[]': keysOf<RoleReference>()({ id: true, name: true }),
  Role: keysOf<Role>()({
    id: true,
    name: true,
    description: true,
    permission_codes: true,
    is_system: true,
    holder_count: true,
    margin_exposure: true,
    archived_at: true,
  }),
  RoleGrantProposal: keysOf<RoleGrantProposal>()({
    id: true,
    user: true,
    role: true,
    permission_codes: true,
    status: true,
    is_expired: true,
    proposed_by: true,
    proposed_at: true,
    expires_at: true,
    decided_by: true,
    decided_at: true,
    reason: true,
    lapse_reason: true,
  }),
  UserDisplayReference: keysOf<UserDisplayReference>()({ id: true, display_name: true }),
  Permission: keysOf<Permission>()({ code: true, module: true, description: true, sod_rows: true }),
  SodRowRef: keysOf<SodRowRef>()({ id: true, half: true }),
  OfferedRole: keysOf<OfferedRole>()({
    id: true,
    name: true,
    conflicts_with: true,
    margin_exposure_with: true,
    refusal: true,
    requires_owner_confirmation: true,
  }),
  RoleConflict: keysOf<RoleConflict>()({ role: true, sod_row: true }),
  Collection: keysOf<Collection<unknown>>()({ items: true, next_cursor: true, previous_cursor: true, tenant_has_rows: true }),
  // Revision 7 A-18 (step 4.1): the warehouse and its update input; the create input and the location shapes join with
  // the commits that wire them.
  Warehouse: keysOf<Warehouse>()({ id: true, code: true, name: true, country_code: true, archived_at: true }),
  'Warehouse.CreateWarehouseInput': keysOf<CreateWarehouseInput>()({ code: true, name: true, country_code: true }),
  'Warehouse.UpdateWarehouseInput.jsonMergePatch': keysOf<UpdateWarehouseInput>()({ code: true, name: true, country_code: true }),
  StorageLocation: keysOf<StorageLocation>()({ id: true, code: true, kind: true, archived_at: true }),
  'StorageLocation.CreateStorageLocationInput': keysOf<CreateStorageLocationInput>()({ code: true, kind: true }),
  'StorageLocation.UpdateStorageLocationInput.jsonMergePatch': keysOf<UpdateStorageLocationInput>()({ code: true, kind: true }),
};

describe('C-1 — wire parity of the hand-written lib/api types', () => {
  it('the generated surface mirrors exactly the types this test checks', () => {
    expect([...mirroredSchemas()].sort()).toEqual(Object.keys(MIRRORED).sort());
  });

  it.each(Object.keys(MIRRORED))('%s has exactly the served property names', (name) => {
    expect(MIRRORED[name]).toEqual(wireFields(name));
  });

  it('an unknown name is an error, not an empty list', () => {
    expect(() => wireFields('NoSuchSchema')).toThrow(/NoSuchSchema/);
  });
});
