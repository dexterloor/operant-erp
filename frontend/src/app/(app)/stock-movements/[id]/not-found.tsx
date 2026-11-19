import { StockMovementNotFound } from '../StockMovementStates';

// ST-5 for `/stock-movements/[id]`: RULED texts, `Back to the list` → `/stock-movements`.
export default function NotFoundPage() {
  return <StockMovementNotFound />;
}
