import { WarehouseNotFound } from '../WarehouseStates';

// ST-5 for `/warehouses/[id]`: RULED texts, `Back to the list` → `/warehouses`.
export default function NotFoundPage() {
  return <WarehouseNotFound />;
}
