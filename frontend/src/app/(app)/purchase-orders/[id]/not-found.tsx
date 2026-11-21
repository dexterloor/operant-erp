import { PurchaseOrderNotFound } from '../PurchaseOrderStates';

// ST-5 for `/purchase-orders/[id]`: RULED texts, `Back to the list` → `/purchase-orders`.
export default function NotFoundPage() {
  return <PurchaseOrderNotFound />;
}
