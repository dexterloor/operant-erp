import { ItemNotFound } from '../ItemStates';

// ST-5 for `/items/[id]`: RULED texts, `Back to the list` → `/items` (items-ux-copy.md B-3 404 row).
export default function NotFoundPage() {
  return <ItemNotFound />;
}
