<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Modules\Sales\Application\CustomerOpenSalesWork;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * **Every leg of `DeleteCustomer`'s open-work statement that finds a customer's sales orders also names the live-row
 * predicate** (`docs/session13/owed-data-c5.md` follow-up 1, option (a); 2026-10-15).
 *
 * `sales_order_tenant_customer_idx` is partial on live rows (`deleted_at IS NULL`), so a leg that filters
 * `<alias>.customer_id = ?` without `<alias>.deleted_at IS NULL` cannot use it, and reads the whole workspace's orders.
 * Leg 4 lacked it until this test (measured by `DATA` on real volume: 20–22 ms → 3–4 ms for a typical customer, counts
 * identical). The predicate moves no count in any reachable state, so **no behaviour test can see it dropped** —
 * `CustomerDeleteTest::theOpenWorkIsCountedInOneStatement` compares the recorded SQL against `statement()` itself.
 * This test reads the text instead. A pure test: no kernel, no database.
 *
 * **Limits, stated:** literal-shaped. It pins the text, not the plan (whether the planner then takes the index is a
 * `SchemaQueryPlanTest` question, not asked here). A leg is one `(SELECT count(*) …)` subquery; an alias is found from
 * `FROM|JOIN sales_order <alias>`, so a quoted or schema-qualified `sales_order` is invisible — the anti-vacuity count
 * below (exactly three aliases, `so`, `fso`, `rso`) is what turns such a rewrite red instead of silently passing.
 * **Mutation:** drop `rso.deleted_at IS NULL` from leg 4 → red, naming `rso`.
 */
final class CustomerOpenWorkStatementTest extends TestCase
{
    #[Test]
    public function everyLegFilteringSalesOrdersByCustomerAlsoNamesTheLiveRowPredicate(): void
    {
        $legs = \array_slice(explode('(SELECT count(*)', CustomerOpenSalesWork::statement()), 1);
        self::assertCount(4, $legs, 'The open-work statement no longer has four count(*) legs; re-read this test before trusting it.');

        $checked = [];
        foreach ($legs as $n => $leg) {
            preg_match_all('/\b(?:FROM|JOIN)\s+sales_order\s+(\w+)/i', $leg, $m);
            foreach ($m[1] as $alias) {
                if (1 !== preg_match('/\b'.preg_quote($alias, '/').'\.customer_id\s*=\s*\?::uuid/', $leg)) {
                    continue;
                }
                $checked[] = $alias;
                self::assertMatchesRegularExpression('/\b'.preg_quote($alias, '/').'\.deleted_at\s+IS\s+NULL\b/', $leg,
                    \sprintf("Leg %d filters sales_order %s by customer without %s.deleted_at IS NULL. sales_order_tenant_customer_idx is\n"
                        .'partial on live rows, so this leg cannot use it and reads every order in the workspace (owed-data-c5.md follow-up 1).',
                        $n + 1, $alias, $alias));
            }
        }

        self::assertSame(['so', 'fso', 'rso'], $checked,
            'Anti-vacuity: the scan did not find exactly the three customer-filtered sales_order aliases (legs 1, 2, 4). '
            .'A rewrite it cannot read would otherwise pass with nothing checked.');
    }
}
