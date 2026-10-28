import { RoleNotFound } from '../RoleStates';

// ST-5 for `/admin/roles/[id]` (ux-copy-b3b.md C7-2): RULED texts, `Back to the list` → `/admin/roles`.
export default function NotFoundPage() {
  return <RoleNotFound />;
}
