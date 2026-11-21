import { GoodsReceiptNotFound } from '../GoodsReceiptStates';

// ST-5 for `/goods-receipts/[id]`: RULED texts, `Back to the list` → `/goods-receipts`.
export default function NotFoundPage() {
  return <GoodsReceiptNotFound />;
}
