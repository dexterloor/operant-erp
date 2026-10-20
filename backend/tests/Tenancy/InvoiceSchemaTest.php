<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use Symfony\Component\Uid\Uuid;

/**
 * **The invoice schema `IssueInvoice` will write into — sales step 6, commit C2.** No operation exists yet, so every
 * row here is written **as the owner**: the subject is the constraint, and constraints bind every role
 * (`attemptAsOwnerForTenant`'s docblock). `erp_app` still holds only SELECT on `invoice` after this commit.
 *
 * - `fulfilment_id` and `issued_by_user_id`, both NOT NULL (owner ruling Q5: *"Column + audit event"*), each under a
 *   composite `(tenant_id, …)` foreign key.
 * - `invoice_tenant_fulfilment_not_void`: at most one **live** invoice per shipment (D-A). A voided one releases it.
 * - `invoice_number_shape` (owner ruling Q12, *"Six (INV-000001)"*), `invoice_total_not_negative`, and
 *   `invoice_due_window`, whose upper bound is computed **in UTC** so it does not move with the session's zone.
 */
final class InvoiceSchemaTest extends TenancyTestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function numbersTheShapeRefuses(): iterable
    {
        yield 'five digits' => ['INV-12345', '23514'];
        yield 'seven digits' => ['INV-1234567', '23514'];
        yield 'another document\'s shape' => ['INV-SO-000001', '23514'];
        yield 'lower-case prefix' => ['inv-000001', '23514'];
        yield 'six digits (the control)' => ['INV-000001', 'accepted'];
    }

    #[\PHPUnit\Framework\Attributes\Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('numbersTheShapeRefuses')]
    public function theNumberIsExactlyInvDashSixDigits(string $number, string $expected): void
    {
        $w = $this->world();

        self::assertSame($expected, $this->attemptInvoice($w, ['invoice_number' => $number]));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function aNegativeTotalIsRefusedAndZeroIsNot(): void
    {
        $w = $this->world();

        self::assertSame('23514', $this->attemptInvoice($w, ['total_amount' => '-0.0001']));
        self::assertSame('accepted', $this->attemptInvoice($w, ['total_amount' => '0']));
    }

    /**
     * **The due window is zone-independent.** Under `Europe/Berlin`, an invoice issued 2026-10-26 12:00Z (the day
     * after DST ends) and due exactly 365 **UTC** days later is accepted; one day more is refused. The naive form,
     * `issued_at + interval '365 days'`, adds days on the session's local calendar and lands an hour early, because
     * 2027-10-26 is before the 2027-10-31 clock change (Berlin is still on summer time there), so it refuses the first
     * row — mutation C2-1, and C0b's F5. (It was 3650 days and the October 2036 boundary until G-35 (f), C0b.)
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function theDueWindowIsComputedInUtcWhateverTheSessionZone(): void
    {
        $w = $this->world();
        $issued = new \DateTimeImmutable('2026-10-26T12:00:00Z');
        $utc = new \DateTimeZone('UTC');

        $atBound = $issued->setTimezone($utc)->modify('+365 days')->format('Y-m-d\TH:i:s\Z');
        $pastBound = $issued->setTimezone($utc)->modify('+366 days')->format('Y-m-d\TH:i:s\Z');

        self::assertSame('accepted', $this->attemptInvoice($w, ['issued_at' => $issued->format('Y-m-d\TH:i:s\Z'), 'due_at' => $atBound], 'Europe/Berlin'));
        self::assertSame('23514', $this->attemptInvoice($w, ['issued_at' => $issued->format('Y-m-d\TH:i:s\Z'), 'due_at' => $pastBound], 'Europe/Berlin'));
        self::assertSame('23514', $this->attemptInvoice($w, ['issued_at' => $issued->format('Y-m-d\TH:i:s\Z'), 'due_at' => '2026-10-26T11:59:59Z']),
            'A due date before the issue instant was accepted.');
        self::assertSame('accepted', $this->attemptInvoice($w, ['issued_at' => $issued->format('Y-m-d\TH:i:s\Z'), 'due_at' => $issued->format('Y-m-d\TH:i:s\Z')]),
            'Zero payment days (due at the issue instant) was refused.');
    }

    /**
     * **One live invoice per shipment, and a void releases it.** Two live invoices for one fulfilment are refused
     * for every pair of live statuses (a predicate narrower than `status <> 'void'` would let one through); a void
     * one does not block the reissue.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function aShipmentHoldsAtMostOneLiveInvoiceAndAVoidReleasesIt(): void
    {
        $w = $this->world();

        foreach (['issued', 'paid', 'overdue'] as $i => $status) {
            $first = $this->insertInvoice($w, 'INV-00001'.$i, $status);
            self::assertSame('23505', $this->attemptInvoice($w, ['invoice_number' => 'INV-00002'.$i]),
                'A second live invoice was accepted beside a '.$status.' one.');
            $this->executeAsOwnerForTenant(self::TENANT_A,
                "UPDATE invoice SET status = 'void', paid_at = NULL, paid_by_user_id = NULL WHERE id = ?::uuid", [$first]);
        }

        // Three voided invoices of one shipment, and the reissue is accepted and kept.
        $this->insertInvoice($w, 'INV-000100', 'issued');
        self::assertSame(4, $this->countAsOwnerForTenant(self::TENANT_A,
            'SELECT count(*) FROM invoice WHERE fulfilment_id = ?::uuid', [$w['fulfilment']]));
    }

    /**
     * **OI-2's invariant, two-way (sales step 6, C5): `paid_at IS NOT NULL ⇔ status = 'paid'`, and the same for the
     * recorder.** All four quadrants of each CHECK, as the owner (constraints bind every role). The one-way
     * `invoice_paid_at_set` this replaced accepted the third quadrant of the first — a `paid_at` left behind on an
     * unpaid invoice — which is exactly what a reversal (C6) must never leave.
     *
     * @return iterable<string, array{string, array<string, string|null>, string}>
     */
    public static function paymentQuadrants(): iterable
    {
        $user = '{user}';
        yield 'paid, with a date and a recorder' => ['paid', [], 'accepted'];
        yield 'paid, with no date' => ['paid', ['paid_at' => null], '23514'];
        yield 'paid, with no recorder' => ['paid', ['paid_by_user_id' => null], '23514'];
        yield 'issued, with a date left behind' => ['issued', ['paid_at' => '2026-09-02T00:00:00Z'], '23514'];
        yield 'issued, with a recorder left behind' => ['issued', ['paid_by_user_id' => $user], '23514'];
        yield 'void, with a date left behind' => ['void', ['paid_at' => '2026-09-02T00:00:00Z'], '23514'];
        yield 'void, with a recorder left behind' => ['void', ['paid_by_user_id' => $user], '23514'];
        yield 'issued, with neither' => ['issued', [], 'accepted'];
        yield 'paid on the issue instant' => ['paid', ['paid_at' => '2026-09-01T00:00:00Z'], 'accepted'];
        yield 'paid before it was issued' => ['paid', ['paid_at' => '2026-08-31T23:59:59Z'], '23514'];
    }

    /** @param array<string, string|null> $override */
    #[\PHPUnit\Framework\Attributes\Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('paymentQuadrants')]
    public function aPaymentDateAndItsRecorderExistExactlyWhenTheInvoiceIsPaid(string $status, array $override, string $expected): void
    {
        $w = $this->world();
        $override = array_map(static fn (?string $v): ?string => '{user}' === $v ? $w['user'] : $v, $override);
        [$sql, $params] = $this->invoiceStatement($w, $override, $status);

        self::assertSame($expected, $this->attemptAsOwnerForTenant(self::TENANT_A, $sql, $params));
    }

    /** The recorder is a composite reference too: another workspace's user is refused (23503). */
    #[\PHPUnit\Framework\Attributes\Test]
    public function thePayerMustBeThisWorkspaces(): void
    {
        $w = $this->world();
        $foreign = $this->world(self::TENANT_B);

        [$sql, $params] = $this->invoiceStatement($w, ['paid_by_user_id' => $foreign['user']], 'paid');
        self::assertSame('23503', $this->attemptAsOwnerForTenant(self::TENANT_A, $sql, $params));
        [$sql, $params] = $this->invoiceStatement($w, [], 'paid');
        self::assertSame('accepted', $this->attemptAsOwnerForTenant(self::TENANT_A, $sql, $params), 'The control: this workspace\'s user.');
    }

    /** The two new references are composite: another workspace's fulfilment or user is refused (23503). */
    #[\PHPUnit\Framework\Attributes\Test]
    public function theFulfilmentAndTheIssuerMustBeThisWorkspaces(): void
    {
        $w = $this->world();
        $b = $this->world(self::TENANT_B);

        self::assertSame('23503', $this->attemptInvoice($w, ['fulfilment_id' => $b['fulfilment']]));
        self::assertSame('23503', $this->attemptInvoice($w, ['issued_by_user_id' => $b['user']]));
        self::assertSame('23502', $this->attemptInvoice($w, ['fulfilment_id' => null]));
        self::assertSame('23502', $this->attemptInvoice($w, ['issued_by_user_id' => null]));
    }

    // -----------------------------------------------------------------------

    private static int $worlds = 400000;

    /** @return array{order: string, customer: string, fulfilment: string, user: string} */
    private function world(string $tenantId = self::TENANT_A): array
    {
        $tag = substr(Uuid::v4()->toRfc4122(), 0, 8);
        // CD-7: `order_number` and `fulfilment_number` are pinned to six digits, so the random tag cannot be their
        // suffix. A process-wide counter instead of a random number, so two worlds never collide (RED F7).
        $number = \sprintf('%06d', ++self::$worlds);
        $user = Uuid::v4()->toRfc4122();
        $this->executeAsOwnerForTenant($tenantId,
            "INSERT INTO app_user (id, tenant_id, email, password_hash, display_name) VALUES (?::uuid, ?::uuid, ?, 'not-a-hash', 'Invoice Fixture')",
            [$user, $tenantId, 'invoice-'.$tag.'@example.test']);
        $customer = Uuid::v4()->toRfc4122();
        $this->executeAsOwnerForTenant($tenantId,
            'INSERT INTO customer (id, tenant_id, code, name, country_code, currency_code) VALUES (?::uuid, ?::uuid, ?, ?, \'GB\', \'EUR\')',
            [$customer, $tenantId, 'C-'.$tag, 'Customer '.$tag]);
        $warehouse = Uuid::v4()->toRfc4122();
        $this->executeAsOwnerForTenant($tenantId,
            'INSERT INTO warehouse (id, tenant_id, code, name, country_code) VALUES (?::uuid, ?::uuid, ?, ?, \'DE\')',
            [$warehouse, $tenantId, 'W-'.$tag, 'Warehouse '.$tag]);
        $order = Uuid::v4()->toRfc4122();
        $this->executeAsOwnerForTenant($tenantId, <<<'SQL'
            INSERT INTO sales_order (id, tenant_id, order_number, customer_id, warehouse_id, currency_code,
                                     ship_to_country_code, status, ordered_at, idempotency_key, request_digest)
            VALUES (?::uuid, ?::uuid, ?, ?::uuid, ?::uuid, 'EUR', 'GB', 'confirmed', now(),
                    encode(sha256(gen_random_uuid()::text::bytea), 'hex'), encode(sha256(gen_random_uuid()::text::bytea), 'hex'))
            SQL, [$order, $tenantId, 'SO-'.$number, $customer, $warehouse]);
        $fulfilment = Uuid::v4()->toRfc4122();
        $this->executeAsOwnerForTenant($tenantId, <<<'SQL'
            INSERT INTO fulfilment (id, tenant_id, sales_order_id, warehouse_id, fulfilment_number, shipped_by_user_id,
                                    idempotency_key, request_digest)
            VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?, ?::uuid,
                    encode(sha256(gen_random_uuid()::text::bytea), 'hex'), encode(sha256(gen_random_uuid()::text::bytea), 'hex'))
            SQL, [$fulfilment, $tenantId, $order, $warehouse, 'FUL-'.$number, $user]);

        return ['order' => $order, 'customer' => $customer, 'fulfilment' => $fulfilment, 'user' => $user];
    }

    /**
     * @param array{order: string, customer: string, fulfilment: string, user: string} $w
     * @param array<string, string|null>                                                 $override
     *
     * @return array{string, list<string|null>}
     */
    private function invoiceStatement(array $w, array $override, string $status = 'issued'): array
    {
        $columns = array_merge([
            'id' => Uuid::v4()->toRfc4122(),
            'tenant_id' => self::TENANT_A,
            'sales_order_id' => $w['order'],
            'customer_id' => $w['customer'],
            'fulfilment_id' => $w['fulfilment'],
            'issued_by_user_id' => $w['user'],
            'invoice_number' => 'INV-000001',
            'status' => $status,
            'currency_code' => 'EUR',
            'total_amount' => '10.0000',
            'issued_at' => '2026-09-01T00:00:00Z',
            'due_at' => '2026-10-01T00:00:00Z',
            'paid_at' => 'paid' === $status ? '2026-09-02T00:00:00Z' : null,
            // Sales step 6 C5: `invoice_paid_by_matches_status` — a paid invoice names its recorder, nothing else does.
            'paid_by_user_id' => 'paid' === $status ? $w['user'] : null,
        ], $override);

        // Every placeholder is typed, so the same expressions serve a VALUES row and the SELECT form below.
        $casts = ['id' => '::uuid', 'tenant_id' => '::uuid', 'sales_order_id' => '::uuid', 'customer_id' => '::uuid',
            'fulfilment_id' => '::uuid', 'issued_by_user_id' => '::uuid', 'total_amount' => '::numeric',
            'issued_at' => '::timestamptz', 'due_at' => '::timestamptz', 'paid_at' => '::timestamptz',
            'paid_by_user_id' => '::uuid'];

        return [
            \sprintf('INSERT INTO invoice (%s) VALUES (%s)', implode(', ', array_keys($columns)),
                implode(', ', array_map(static fn (string $c): string => '?'.($casts[$c] ?? '::text'), array_keys($columns)))),
            array_values($columns),
        ];
    }

    /**
     * One invoice INSERT as the owner, always rolled back: `accepted` or its SQLSTATE. With a zone, the INSERT reads
     * its rows through a `set_config('TimeZone', …, true)` in the same statement, so the CHECK is evaluated in that
     * session zone and the setting dies with the rollback -- the tenant GUC is still set only by the helper.
     *
     * @param array{order: string, customer: string, fulfilment: string, user: string} $w
     * @param array<string, string|null>                                                 $override
     */
    private function attemptInvoice(array $w, array $override, ?string $zone = null): string
    {
        [$sql, $params] = $this->invoiceStatement($w, $override);
        if (null !== $zone) {
            $sql = preg_replace('/ VALUES \((.*)\)$/', ' SELECT $1 FROM (SELECT set_config(\'TimeZone\', ?, true)) AS zone', $sql) ?? $sql;
            $params[] = $zone;
        }

        return $this->attemptAsOwnerForTenant(self::TENANT_A, $sql, $params);
    }

    /** @param array{order: string, customer: string, fulfilment: string, user: string} $w */
    private function insertInvoice(array $w, string $number, string $status): string
    {
        [$sql, $params] = $this->invoiceStatement($w, ['invoice_number' => $number], $status);
        $this->executeAsOwnerForTenant(self::TENANT_A, $sql, $params);

        return (string) $params[0];
    }
}
