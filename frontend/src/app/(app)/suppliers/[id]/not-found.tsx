import { SupplierNotFound } from '../SupplierStates';

// ST-5 for `/suppliers/[id]`: RULED texts, `Back to the list` → `/suppliers` (suppliers-ux-copy.md B-3 404 row).
export default function NotFoundPage() {
  return <SupplierNotFound />;
}
