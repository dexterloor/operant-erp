<?php

declare(strict_types=1);

namespace App\Tests\Api;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **Users slice 1 C2 — `ListUsers`** (`docs/session13/users-s1-design.md` rev 3 §2.3, §8 mutations 10–13).
 *
 * Bounded, ordered `email, id`; `archived` (API-19c limb 1) and `q` (TB-5 `app_user.email`, a case-insensitive PREFIX —
 * never `display_name`, never a substring); each item is `GetUser`'s body. The registry-wide list checks
 * (`ListEnvelopeTest`, `QueryParameterContractTest`, `SearchContractTest`) drive it through `ListOperationFixtures`; this
 * class pins what they cannot see — `SearchContractTest` cannot tell a prefix from an infix (mutation 10).
 */
final class UserListTest extends ApiTestCase
{
    /** Each item is exactly `GetUser`'s body for that user (one DTO, the same shape). */
    #[Test]
    public function eachItemIsTheGetUserBody(): void
    {
        $user = $this->seedUser('bea@example.test', 'Bea');
        $role = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, 'INSERT INTO role (id, tenant_id, name) VALUES (?::uuid, ?::uuid, ?)', [$role, self::TENANT_A, 'Listed role']);
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'INSERT INTO user_role (id, tenant_id, user_id, role_id) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, ?::uuid)', [self::TENANT_A, $user, $role]);

        $this->send('GET', '/users');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $page = $this->json();
        self::assertSame([null, null], [$page['next_cursor'], $page['previous_cursor']], 'A bounded list carries a cursor.');
        self::assertCount(\count($page['items']), array_unique(array_column($page['items'], 'id')));

        foreach ($page['items'] as $item) {
            $this->send('GET', '/users/'.$item['id']);
            self::assertSame($this->json(), $item, 'A ListUsers item is not GetUser\'s body for '.$item['id']);
        }
        self::assertContains($user, array_column($page['items'], 'id'));
    }

    /** Mutation 12: ordered by `email`, then `id` — never `display_name`. */
    #[Test]
    public function usersAreOrderedByEmail(): void
    {
        $this->seedUser('zed@example.test', 'Aaron');
        $this->seedUser('amy@example.test', 'Zoe');

        $this->send('GET', '/users');
        $emails = array_column($this->json()['items'], 'email');
        $expected = array_column($this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT email FROM app_user WHERE deleted_at IS NULL ORDER BY email, id'), 'email');
        self::assertSame($expected, $emails);
        self::assertLessThan(array_search('zed@example.test', $emails, true), array_search('amy@example.test', $emails, true));
    }

    /**
     * Mutations 10 and 11: `q` is a case-insensitive prefix of `email` — `q=ALICE` finds alice; `q=bob` does NOT find
     * `alice.bob@…` (infix); a display name is never searched; `%` and `_` match themselves.
     */
    #[Test]
    public function aSearchIsACaseInsensitiveEmailPrefix(): void
    {
        $this->seedUser('alice.bob@example.test', 'Bob Display');
        $this->seedUser('alice_x@example.test', 'Underscore');
        $this->seedUser('aliceyx@example.test', 'Not underscore');

        self::assertSame(['alice.bob@example.test', 'alice_x@example.test', 'aliceyx@example.test'], $this->emails('?q=ALICE'), 'q is not case-insensitive.');
        self::assertSame([], $this->emails('?q=bob'), 'q matched inside an email or a display name: it is not an email prefix.');
        self::assertSame([], $this->emails('?q=Bob%20Display'), 'q searched display_name.');
        self::assertSame(['alice_x@example.test'], $this->emails('?q=alice_'), '_ is a wildcard, not itself.');
    }

    /**
     * Mutation 13 (first half): `archived=true` returns the removed users only, `archived=false` (and none) the live ones —
     * a deactivated user is live. `tenant_has_rows` stays true on an empty filtered page.
     */
    #[Test]
    public function theArchivedViewHoldsOnlyRemovedUsers(): void
    {
        $removed = $this->seedUser('removed@example.test', 'Removed');
        $inactive = $this->seedUser('inactive@example.test', 'Inactive');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET deleted_at = now() WHERE id = ?::uuid', [$removed]);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET is_active = false WHERE id = ?::uuid', [$inactive]);

        self::assertSame(['removed@example.test'], $this->emails('?archived=true'));
        self::assertNotNull($this->json()['items'][0]['archived_at']);
        self::assertSame([], $this->json()['items'][0]['roles']);
        foreach (['', '?archived=false'] as $query) {
            $emails = $this->emails($query);
            self::assertContains('inactive@example.test', $emails, 'A deactivated user is missing from the live list.');
            self::assertNotContains('removed@example.test', $emails, 'A removed user is in the live list.');
        }
        self::assertSame([], $this->emails('?q=nobody'));
        self::assertTrue($this->json()['tenant_has_rows']);

        $this->send('GET', '/users?archived=maybe');
        self::assertSame(422, $this->httpStatus());
    }

    // -----------------------------------------------------------------------

    /** @return list<string> */
    private function emails(string $query): array
    {
        $this->send('GET', '/users'.$query);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        return array_values(array_filter(array_column($this->json()['items'], 'email'), static fn (string $e): bool => !str_starts_with($e, 'actor-')));
    }

    private function seedUser(string $email, string $displayName): string
    {
        $user = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A,
            "INSERT INTO app_user (id, tenant_id, email, password_hash, display_name) VALUES (?::uuid, ?::uuid, ?, 'not-a-hash', ?)",
            [$user, self::TENANT_A, $email, $displayName]);

        return $user;
    }
}
