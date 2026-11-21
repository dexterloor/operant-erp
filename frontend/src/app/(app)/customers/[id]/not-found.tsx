import { CustomerNotFound } from '../CustomerStates';

// ST-5 for `/customers/[id]`: RULED texts, `Back to the list` → `/customers` (the supplier page's 404 row).
export default function NotFoundPage() {
  return <CustomerNotFound />;
}
