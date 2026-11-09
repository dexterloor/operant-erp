<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Tests\Support\OwnerRequestWindow;
use ErpDemoSeed\Locality;
use ErpDemoWalkthrough\Plan;
use PHPUnit\Framework\Attributes\Test;

/**
 * **2a C4: `demo-walkthrough.php walk` — the work and the refusals, on a populated workspace, twice** (`demo-seed-2a-design.md`
 * Revision 1 §R1-5/§R1-6, final RED M-2/M-3/M-4).
 *
 * Populate runs first on one kernel (the in-memory mail queue must survive); the walk then runs with a **kernel reboot per
 * request**, because a refused request closes the shared entity manager and the walk is mostly refusals. A reboot replaces
 * the container, and with it `OwnerRequestWindow`, so this test takes each request's window as it goes and asserts the
 * whole list is empty at the end (final RED M-3), rather than leaving `ApiTestCase::tearDown` to see the last request only.
 *
 * The script asserts each refusal itself (status, problem type and rule, from `Plan::REFUSALS`) and stops on any other
 * answer, so an exit of 0 IS the evidence for R1–R8. Asserts: two walks in a row exit 0 (re-runnability, R5's reject and
 * R6's live on-hand doing their job); every refusal line was printed; the isolation step answered 404 for a second seeded
 * workspace; nothing secret was printed; no `*_at` field was sent; on-hand in A-01 grew by 6 per walk; the owner's
 * connection issued nothing during any request.
 * Mutations: M11 (DSoD-1 returns early) → R3 answers 200 → exit 4; M12 (an expectation's rule changed) → exit 4; M16 (R5's
 * reject becomes a confirm) → the second walk stops (Vic now holds the role); M17′ (the second walk reuses the first walk's
 * on-hand) → R6's shipment succeeds → exit 4.
 */
final class DemoWalkthroughTest extends AuthTestCase
{
    use DemoPlanRig;

    /** @var list<string> */
    private array $ownerStatements = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner()->executeStatement('DELETE FROM tenant WHERE slug LIKE ?', [Locality::TEST_SLUG_PREFIX.'%']);
        $this->client()->disableReboot();
        $this->client()->setServerParameter('REMOTE_ADDR', '127.0.0.1');
    }

    protected function afterRequest(): void
    {
        $window = self::getContainer()->get(OwnerRequestWindow::class);
        \assert($window instanceof OwnerRequestWindow);
        array_push($this->ownerStatements, ...$window->takeStatementsInsideRequests());
    }

    #[Test]
    public function theWalkShowsTheWorkAndEveryRefusalAndCanBeRunAgain(): void
    {
        $slug = self::demoSlug();
        $other = self::demoSlug();
        $password = bin2hex(random_bytes(12));
        $this->seed($slug, $password);
        $this->seed($other, $password);
        $tenantId = (string) $this->owner()->fetchOne('SELECT id::text FROM tenant WHERE slug = ?', [$slug]);
        [, $exit, $output] = $this->runPlan($slug, $password, fn (Plan $plan) => $plan->fillWorkspace($this->mailbox()));
        self::assertSame(Plan::EXIT_OK, $exit, $output);

        $this->client()->enableReboot();
        foreach ([41 => 'first', 47 => 'second'] as $expectedOnHand => $run) {
            [$plan, $exit, $output] = $this->runPlan($slug, $password, fn (Plan $plan) => $plan->walk(true, $other));
            self::assertSame(Plan::EXIT_OK, $exit, 'The '.$run.' walk stopped: '.$output);
            foreach (array_keys(Plan::REFUSALS) as $refusal) {
                self::assertStringContainsString('['.$refusal.'] ', $output, 'The '.$run.' walk printed no '.$refusal.' line.');
            }
            self::assertStringContainsString('the database\'s row-level security hid the row', $output);
            foreach ($this->secrets as $secret) {
                self::assertStringNotContainsString($secret, $output, 'The output carries a secret.');
            }
            self::assertSame([], self::timestampKeys($plan->bodies));
            self::assertSame(bcadd((string) $expectedOnHand, '0', 6), (string) $this->fetchAsOwnerForTenant($tenantId,
                "SELECT sum(s.qty_on_hand)::text AS q FROM stock_level s JOIN storage_location l ON l.id = s.storage_location_id WHERE l.code = 'A-01'")[0]['q']);
        }
        self::assertSame([], $this->ownerStatements, 'The schema owner\'s connection issued statements while a walk request was served.');
    }

    /**
     * @param list<array<string, mixed>> $bodies
     *
     * @return list<string>
     */
    private static function timestampKeys(array $bodies): array
    {
        $found = [];
        array_walk_recursive($bodies, static function (mixed $value, int|string $key) use (&$found): void {
            if (\is_string($key) && str_ends_with($key, '_at')) {
                $found[] = $key;
            }
        });

        return $found;
    }
}
