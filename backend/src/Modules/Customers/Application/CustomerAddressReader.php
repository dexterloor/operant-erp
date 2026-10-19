<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`ListCustomerAddresses`** (customers slice 1 C3; `docs/session13/customers-s1-design.md` §5), the row load behind
 * `UpdateCustomerAddress`, and the address read-back the writer shares — as {@see CustomerAddressView}s.
 *
 * *(bounded)* (TB-1c: a customer's addresses are a bounded sub-list inside its detail page): no query parameters, the
 * whole set ordered `kind, created_at, id` (`ListPurchaseOrderLines`' bounded shape). **The parent resolves first and
 * includes an archived customer** (§5.2a: reading an archived row's data is the direct-hit case, final RED L-4 (a)); an
 * unknown or another workspace's customer is null, the caller's 404.
 *
 * **One address is always named by the PAIR `(customer, address)`** ({@see self::readInside()}): an address of another
 * customer under this customer's path is the same not-found as a random id (ST-5), never an edit of that customer's row.
 * The provider, the writer's in-transaction read and the read-back all go through that one predicate.
 *
 * Every statement runs under `TenantContext::run()` on the entity manager's own connection (`erp_app`, under RLS), never a
 * `Doctrine\DBAL\Connection` (A-10). `region` is a column, not a field (it is neither accepted nor served), and the retry
 * key and digest are never read into a view.
 */
final readonly class CustomerAddressReader
{
    /**
     * The served columns, unqualified (customers slice 2 C3, final RED F-L2): the list, the pair read and the writer's
     * locked key read all select these and map them through {@see self::view()}, so a column added to the view appears on
     * create, replay and list alike.
     */
    public const COLUMNS = 'id, kind, address_line, city, postal_code, country_code';

    private const SELECT = 'SELECT '.self::COLUMNS.' FROM customer_address a';

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
    ) {
    }

    /** @return ?list<CustomerAddressView> null when the customer is not visible (404); archived customers included */
    public function list(string $customerId): ?array
    {
        if (!Uuid::isValid($customerId)) {
            return null;
        }
        $customerId = Uuid::fromString($customerId)->toRfc4122();

        return $this->tenants->run($this->tenant->tenantId(), static function (EntityManagerInterface $em) use ($customerId): ?array {
            $connection = $em->getConnection();
            // No `deleted_at` predicate: an archived customer's addresses are read (§5.2a).
            if (false === $connection->fetchOne('SELECT 1 FROM customer WHERE id = ?::uuid', [$customerId])) {
                return null;
            }

            return array_map(self::view(...), $connection->fetchAllAssociative(
                self::SELECT.' WHERE a.customer_id = ?::uuid ORDER BY a.kind, a.created_at, a.id', [$customerId]));
        });
    }

    /** API-22's probe for an empty set: does this workspace hold any address at all (RLS, no filter predicate). */
    public function tenantHasRows(): bool
    {
        return $this->tenants->run(
            $this->tenant->tenantId(),
            static fn (EntityManagerInterface $em): bool => (bool) $em->getConnection()->fetchOne('SELECT EXISTS (SELECT 1 FROM customer_address)'),
        );
    }

    /** `UpdateCustomerAddress`'s row load: the pair, or null (404). */
    public function get(string $customerId, string $addressId): ?CustomerAddressView
    {
        if (!Uuid::isValid($customerId) || !Uuid::isValid($addressId)) {
            return null;
        }

        return $this->tenants->run(
            $this->tenant->tenantId(),
            static fn (EntityManagerInterface $em): ?CustomerAddressView => self::readInside(
                $em,
                Uuid::fromString($customerId)->toRfc4122(),
                Uuid::fromString($addressId)->toRfc4122(),
            ),
        );
    }

    /**
     * One address **of this customer**, inside a transaction the caller holds; null when it is another customer's or
     * absent. The `customer_id` predicate is the ONE place an address is tied to its path's customer.
     */
    public static function readInside(EntityManagerInterface $em, string $customerId, string $addressId): ?CustomerAddressView
    {
        $row = $em->getConnection()->fetchAssociative(
            self::SELECT.' WHERE a.id = ?::uuid AND a.customer_id = ?::uuid',
            [$addressId, $customerId],
        );

        return false === $row ? null : self::view($row);
    }

    /** @param array<string, mixed> $row a row carrying at least {@see self::COLUMNS} */
    public static function view(array $row): CustomerAddressView
    {
        return new CustomerAddressView(
            id: (string) $row['id'],
            kind: (string) $row['kind'],
            line: (string) $row['address_line'],
            city: (string) $row['city'],
            postalCode: (string) $row['postal_code'],
            countryCode: (string) $row['country_code'],
        );
    }
}
