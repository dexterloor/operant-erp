<?php

declare(strict_types=1);

namespace App\Tests\Api;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **The credit-note schema's own refusals** (`Version20261001000500`, sales step 7 C0; pinned at C1 with its writer).
 * The subject is the constraint, so every row here is written **as the owner** onto a shipment and invoice the API
 * wrote; constraints bind every role (`attemptAsOwnerForTenant`'s docblock).
 *
 * - `credit_note_number_shape`: `CN-` + exactly six digits (CD-7 / Q12; `IA.md` D-f).
 * - `credit_note_total_positive`: `total_amount > 0` — load-bearing for TB-4's Credited segment (no INSERT lowers a sum).
 * - `credit_note_return_iff_goods_returned`: the four quadrants of `(reason = 'goods_returned') = (sales_return_id IS NOT NULL)`.
 * - `credit_note_reason_values`; lines: `quantity > 0`, `amount >= 0` (a telescoped line may be 0), one line per shipped
 *   line per note.
 * - **The G-15 residual pin**: a note whose lines name ANOTHER shipment's line and whose total is not Σ lines is
 *   ACCEPTED — application rules, not constraints. Red the day the `BEFORE INSERT` checks land: close the row then.
 */
final class CreditNoteSchemaTest extends PurchaseOrderTestCase
{
    use InvoiceFixtures;
    use CreditFixtures;
    use CreditNoteFixtures;

    /** @return iterable<string, array{array<string, string|null>, string}> */
    public static function headers(): iterable
    {
        yield 'the control' => [[], 'accepted'];
        yield 'five-digit number' => [['credit_note_number' => 'CN-12345'], '23514'];
        yield 'seven-digit number' => [['credit_note_number' => 'CN-1234567'], '23514'];
        yield 'another document\'s prefix' => [['credit_note_number' => 'RET-000001'], '23514'];
        yield 'a zero total' => [['total_amount' => '0'], '23514'];
        yield 'a negative total' => [['total_amount' => '-1'], '23514'];
        yield 'an unknown reason' => [['reason' => 'goodwill'], '23514'];
        yield 'goods_returned without a return' => [['reason' => 'goods_returned', 'sales_return_id' => null], '23514'];
        yield 'an adjustment naming a return' => [['reason' => 'short_shipped', 'sales_return_id' => '@return'], '23514'];
        yield 'goods_returned with its return' => [['reason' => 'goods_returned', 'sales_return_id' => '@return'], 'accepted'];
        yield 'a malformed retry key' => [['idempotency_key' => 'not-hex'], '23514'];
    }

    /** @param array<string, string|null> $override */
    #[Test]
    #[DataProvider('headers')]
    public function theHeaderConstraintsHold(array $override, string $expected): void
    {
        $w = $this->world('CS-H');
        $row = [
            'id' => Uuid::v4()->toRfc4122(), 'credit_note_number' => 'CN-900100', 'reason' => 'damaged_goods_kept',
            'sales_return_id' => null, 'total_amount' => '1', 'idempotency_key' => hash('sha256', Uuid::v4()->toRfc4122()),
        ];
        foreach ($override as $column => $value) {
            $row[$column] = '@return' === $value ? $w['return'] : $value;
        }

        self::assertSame($expected, $this->attemptAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO credit_note (id, tenant_id, invoice_id, sales_return_id, credit_note_number, reason, currency_code,
                                     total_amount, issued_by_user_id, idempotency_key, request_digest)
            SELECT ?::uuid, inv.tenant_id, inv.id, ?::uuid, ?, ?, inv.currency_code, ?::numeric, ?::uuid, ?,
                   encode(sha256(gen_random_uuid()::text::bytea), 'hex')
              FROM invoice inv WHERE inv.id = ?::uuid
            SQL, [$row['id'], $row['sales_return_id'], $row['credit_note_number'], $row['reason'], $row['total_amount'],
                (string) $this->actorId, $row['idempotency_key'], $w['invoice']]));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function lines(): iterable
    {
        yield 'the control' => ['1', '1', 'accepted'];
        yield 'a zero amount (a telescoped line)' => ['1', '0', 'accepted'];
        yield 'a negative amount' => ['1', '-1', '23514'];
        yield 'a zero quantity' => ['0', '1', '23514'];
    }

    #[Test]
    #[DataProvider('lines')]
    public function theLineConstraintsHold(string $quantity, string $amount, string $expected): void
    {
        $w = $this->world('CS-L');
        $note = $this->seedCreditNote($w['invoice'], '1');

        self::assertSame($expected, $this->attemptAsOwnerForTenant(self::TENANT_A,
            'INSERT INTO credit_note_line (id, tenant_id, credit_note_id, fulfilment_line_id, quantity, amount) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric, ?::numeric)',
            [Uuid::v4()->toRfc4122(), self::TENANT_A, $note, $w['lines'][0], $quantity, $amount]));
    }

    #[Test]
    public function aNoteCreditsAShippedLineOnce(): void
    {
        $w = $this->world('CS-ONCE');
        $note = $this->seedCreditNote($w['invoice'], '1', [[$w['lines'][0], '1', '1']]);

        self::assertSame('23505', $this->attemptAsOwnerForTenant(self::TENANT_A,
            'INSERT INTO credit_note_line (id, tenant_id, credit_note_id, fulfilment_line_id, quantity, amount) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, 1, 1)',
            [Uuid::v4()->toRfc4122(), self::TENANT_A, $note, $w['lines'][0]]));
    }

    /**
     * **G-15's residual, widened at sales step 7** (`RESIDUAL_GAPS.md` G-15; design §11 R-3, §13 item 9): the database
     * ACCEPTS a credit note whose line names another shipment's line and whose total is not the sum of its lines.
     * `CreditNoteWriter` refuses both; SQL as `erp_app` (INSERT since C1) need not.
     */
    #[Test]
    public function residualG15_theSchemaAcceptsACreditNoteThatBreaksTheApplicationsRules(): void
    {
        $w = $this->world('CS-G15A');
        $other = $this->world('CS-G15B');

        self::assertNotSame('', $this->seedCreditNote($w['invoice'], '99', [[$other['lines'][0], '1', '1']]),
            'A credit note naming another shipment\'s line with a total that is not its lines\' sum was refused: G-15\'s closing trigger may have landed -- close the row.');
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM credit_note WHERE total_amount = 99'));
    }

    /** @return array{invoice: string, number: string, fulfilment: string, order: string, lines: list<string>, bin: string, return: string} */
    private function world(string $tag): array
    {
        $i = $this->invoicedShipment($tag, [['4', '10.0000', '2']]);
        $this->actAsRole(\App\Modules\UserManagement\Domain\DefaultRoles::OWNER);

        return $i + ['return' => $this->returnOf($i, [[0, '1']])];
    }
}
