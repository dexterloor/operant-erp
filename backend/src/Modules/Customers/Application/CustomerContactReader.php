<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`ListCustomerContacts`** (customers slice 2 C1; `docs/session13/customers-s2-design.md` §2.2 and Revision 2 R2.2), the
 * row load behind `UpdateCustomerContact`, and the contact read-back the writer shares — as {@see CustomerContactView}s.
 * {@see CustomerAddressReader}'s shape.
 *
 * *(bounded)* (TB-1c: a customer's contacts are a bounded sub-list inside its detail page): no query parameters, the whole
 * set ordered `is_primary DESC, created_at, id` (the primary first, then oldest first; `UX` TO CONFIRM). **The parent
 * resolves first and includes an archived customer** (§5.2a); an unknown or another workspace's customer is null, the
 * caller's 404. **One contact is always named by the PAIR `(customer, contact)`** ({@see self::readInside()}): another
 * customer's contact under this customer's path is the same not-found as a random id (ST-5).
 *
 * **One projection** (final RED F-L2): {@see self::COLUMNS} and {@see self::view()} are public so the writer's locked key
 * read selects exactly these columns and maps them through this mapper — a column added to the view then appears on
 * create, replay and list alike.
 *
 * Every statement runs under `TenantContext::run()` on the entity manager's own connection (`erp_app`, under RLS), never a
 * `Doctrine\DBAL\Connection` (A-10). `erp_ai` holds nothing on the table (C0, SEC S-2).
 */
final readonly class CustomerContactReader
{
    /** The served columns, unqualified: the list, the pair read and the writer's key read all select these. */
    public const COLUMNS = 'id, name, email, phone, is_primary';

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
    ) {
    }

    /** @return ?list<CustomerContactView> null when the customer is not visible (404); archived customers included */
    public function list(string $customerId): ?array
    {
        if (!Uuid::isValid($customerId)) {
            return null;
        }
        $customerId = Uuid::fromString($customerId)->toRfc4122();

        return $this->tenants->run($this->tenant->tenantId(), static function (EntityManagerInterface $em) use ($customerId): ?array {
            $connection = $em->getConnection();
            // No `deleted_at` predicate: an archived customer's contacts are read (§5.2a).
            if (false === $connection->fetchOne('SELECT 1 FROM customer WHERE id = ?::uuid', [$customerId])) {
                return null;
            }

            return array_map(self::view(...), $connection->fetchAllAssociative(
                'SELECT '.self::COLUMNS.' FROM customer_contact WHERE customer_id = ?::uuid ORDER BY is_primary DESC, created_at, id',
                [$customerId],
            ));
        });
    }

    /** API-22's probe for an empty set: does this workspace hold any contact at all (RLS, no filter predicate). */
    public function tenantHasRows(): bool
    {
        return $this->tenants->run(
            $this->tenant->tenantId(),
            static fn (EntityManagerInterface $em): bool => (bool) $em->getConnection()->fetchOne('SELECT EXISTS (SELECT 1 FROM customer_contact)'),
        );
    }

    /** `UpdateCustomerContact`'s row load: the pair, or null (404). */
    public function get(string $customerId, string $contactId): ?CustomerContactView
    {
        if (!Uuid::isValid($customerId) || !Uuid::isValid($contactId)) {
            return null;
        }

        return $this->tenants->run(
            $this->tenant->tenantId(),
            static fn (EntityManagerInterface $em): ?CustomerContactView => self::readInside(
                $em,
                Uuid::fromString($customerId)->toRfc4122(),
                Uuid::fromString($contactId)->toRfc4122(),
            ),
        );
    }

    /**
     * One contact **of this customer**, inside a transaction the caller holds; null when it is another customer's or absent.
     * The `customer_id` predicate is the ONE place a contact is tied to its path's customer.
     */
    public static function readInside(EntityManagerInterface $em, string $customerId, string $contactId): ?CustomerContactView
    {
        $row = $em->getConnection()->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM customer_contact WHERE id = ?::uuid AND customer_id = ?::uuid',
            [$contactId, $customerId],
        );

        return false === $row ? null : self::view($row);
    }

    /** @param array<string, mixed> $row a row carrying at least {@see self::COLUMNS} */
    public static function view(array $row): CustomerContactView
    {
        return new CustomerContactView(
            id: (string) $row['id'],
            name: (string) $row['name'],
            email: null === $row['email'] ? null : (string) $row['email'],
            phone: null === $row['phone'] ? null : (string) $row['phone'],
            isPrimary: true === $row['is_primary'],
        );
    }
}
