<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Modules\UserManagement\Domain\GrantConflict;
use App\Modules\UserManagement\Domain\MarginExposure;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **The margin warning switches on for the catalogue** — suppliers slice 1 C3 (`docs/session13/suppliers-s1-design.md`
 * §5.2 item 5 as revised per RED M-1; `SECURITY_MODEL.md` PC-12 clauses 3, 4 and 6; owner notice (b) of session 23).
 *
 * From this commit `supplier_product.view` (the list) and `supplier_product.edit` (the writes' 200/201, PC-17) are cost
 * sources (`MarginSources`), because an operation now serves `supplier_product.unit_cost`. The two positive tests are what
 * proves the switch-on over HTTP — before C3 both read `false` / `[]`:
 *
 *  - **clause 3, the held union:** a composed role holding `supplier_product.view` + `invoice.view` is created (201, never
 *    refused) with `margin_exposure: true`, and a holder's `GetUser` says the same. **Its picker item lists no partner
 *    (`margin_exposure_with: []`)** — `ListOfferedRoles` warns about PAIRS whose union exposes while neither member does
 *    (`OfferedRoleEvaluator::marginExposureWith()` returns `[]` for a role exposed on its own); the warning for such a role
 *    is its own `margin_exposure`, at definition (`RESIDUAL_GAPS.md` G-28: the pair warning's data is served and rendered
 *    nowhere);
 *  - **clause 4, the pair:** a composed role holding `supplier_product.view` ALONE is not exposed, and its picker item
 *    names every live default it would expose with — **derived here from `MarginExposure` and `GrantConflict`, never
 *    typed**, plus a literal floor (`finance`, `sales_rep`, `viewer` hold a sale source) so the derivation cannot drift
 *    with the code.
 *
 * Mutation (design §5.2): drop `['supplier_product.view']` from `MarginSources::COST_SOURCES` → both tests red, and
 * `MarginSourcesDerivationTest::theCommittedSourcesAreWhatTheContractDerives` red.
 */
final class SupplierProductMarginTest extends ApiTestCase
{
    use RoleGrantFixtures;

    #[Test]
    public function aComposedRoleReadingCatalogueCostAndSalePriceIsWarnedNeverRefused(): void
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        $this->send('POST', '/roles', ['name' => 'Margin by catalogue', 'permission_codes' => ['supplier_product.view', 'invoice.view']],
            [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $role = (string) $this->json()['id'];
        self::assertTrue($this->json()['margin_exposure'], 'PC-12 clause 3: catalogue cost + sale price in one composed role is not warned at definition.');

        $this->send('GET', '/roles/'.$role);
        self::assertTrue($this->json()['margin_exposure'], 'GetRole does not carry the warning.');

        $holder = $this->holderOf([$role]);
        $this->send('GET', '/users/'.$holder);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertTrue($this->json()['margin_exposure'], 'GetUser does not warn about a holder of the exposed role.');

        // The picker: an already-exposed role pairs with nobody; its own flag is the warning (G-28).
        $subject = $this->holderOf([]);
        $item = $this->offeredItem($subject, $role);
        self::assertSame([], $item['margin_exposure_with'], 'A role exposed on its own was offered margin partners.');
    }

    #[Test]
    public function aCatalogueReaderIsWarnedAgainstEveryDefaultThatHoldsASalePrice(): void
    {
        $catalogueOnly = $this->composedRole(['supplier_product.view']);
        $defaults = [];
        foreach (array_keys(DefaultRoles::all()) as $name) {
            $defaults[$this->systemRole($name)] = $name;
        }
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));

        $this->send('GET', '/roles/'.$catalogueOnly);
        self::assertFalse($this->json()['margin_exposure'], 'supplier_product.view alone is exposed: it is a cost source only.');

        // Derived, never typed: every live default not exposed alone whose union with the catalogue role exposes and that
        // PC-13 does not refuse outright (the evaluator's rule, applied here to codes the test seeded).
        $expected = [];
        foreach ($defaults as $id => $name) {
            $codes = DefaultRoles::codesFor($name);
            if (MarginExposure::of($codes)) {
                continue;
            }
            if (MarginExposure::of(array_values(array_unique(['supplier_product.view', ...$codes])))
                && [] === GrantConflict::newRows(['supplier_product.view'], $codes)) {
                $expected[] = $name;
            }
        }
        sort($expected);
        self::assertNotSame([], $expected, 'No default pairs with the catalogue role: supplier_product.view is not a cost source.');
        foreach (['finance', 'sales_rep', 'viewer'] as $saleHolder) {
            self::assertContains($saleHolder, $expected, 'Literal floor: '.$saleHolder.' holds invoice.view, so it must pair.');
        }

        $item = $this->offeredItem($this->holderOf([]), $catalogueOnly);
        $partners = [];
        foreach ($item['margin_exposure_with'] as $partner) {
            if (isset($defaults[$partner['id']])) {
                $partners[] = $defaults[$partner['id']];
            }
        }
        sort($partners);
        self::assertSame($expected, $partners, 'PC-12 clause 4: the picker does not warn the catalogue role against the derived defaults.');
    }

    /** @return array<string, mixed> */
    private function offeredItem(string $subject, string $role): array
    {
        $this->send('GET', '/users/'.$subject.'/offered-roles');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        foreach ($this->json()['items'] as $item) {
            if ($item['id'] === $role) {
                return $item;
            }
        }
        self::fail('The picker does not offer the role.');
    }
}
