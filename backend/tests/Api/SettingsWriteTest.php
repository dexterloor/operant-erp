<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Application\SettingsWriter;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * `UpdateSettings` — `PATCH /api/v1/settings`, `{display_name}` (G7b, 2026-10-22; owner ruling RO-S1,
 * `docs/session13/remaining-ops-ruling.md`; `g3-g7b-reorder-settings-design.md` §3.3/§3.6 + Revision 1 FINAL; final RED
 * F-M3).
 *
 * The rename lives in `tenant_settings.display_name` and **never reaches `tenant`** (no RLS there); the currency stays
 * read-only; the event is `tenant_settings.updated` `{"fields": ["display_name"], "to": …}` — no `from` (design M-5).
 *
 * **The races are real second connections** (`support/hold_order_lock.php`, `ItemDeleteTest`'s shape): the holder takes
 * `SettingsWriter::LOCK` itself and renames (uncommitted), the HTTP request is the second connection.
 */
final class SettingsWriteTest extends ApiTestCase
{
    private const HOLDER_DEADLINE_SECONDS = 10.0;

    #[Test]
    public function theOwnerRenamesTheWorkspace(): void
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        $before = $this->stored(self::TENANT_A);

        $this->send('PATCH', '/settings', ['display_name' => 'Acme Trading']);

        self::assertSame(200, $this->httpStatus(), $this->responseContent());
        self::assertSame(['display_name' => 'Acme Trading', 'workspace' => 'tenant-a', 'default_currency_code' => 'EUR'], $this->json());
        $after = $this->stored(self::TENANT_A);
        self::assertSame('Acme Trading', $after['display_name']);
        self::assertNotSame($before['updated_at'], $after['updated_at'], 'A rename did not move updated_at.');

        $rows = $this->auditRowsOf(AuditEvent::TENANT_SETTINGS_UPDATED);
        self::assertCount(1, $rows);
        $metadata = $rows[0]['metadata'];
        unset($rows[0]['metadata']);
        self::assertSame([
            'event_type' => AuditEvent::TENANT_SETTINGS_UPDATED,
            'actor_user_id' => $this->actorId,
            'actor_label' => 'Fixture Actor',
            'subject_type' => 'tenant_settings',
            'subject_id' => $after['id'],
            'ip_address' => null,
            'permission_code' => null,
        ], $rows[0]);
        // F-M3: exactly `{fields, to}` — no `from` key (design M-5).
        self::assertSame(['fields' => ['display_name'], 'to' => 'Acme Trading'], self::sortedKeys(json_decode($metadata, true, flags: \JSON_THROW_ON_ERROR)));

        $this->send('GET', '/settings');
        self::assertSame('Acme Trading', $this->json()['display_name'], 'GetSettings does not serve the renamed value.');
    }

    /**
     * **Only the owner marker may rename** — derived from `DefaultRoles`, never a typed list: every default role that lacks
     * `tenant_settings.edit` is 403 naming it, with its E-3 denial row; every one that holds it renames.
     */
    #[Test]
    public function onlyTheOwnerMarkerMayRename(): void
    {
        $refused = [];
        $allowed = [];
        foreach (DefaultRoles::names() as $role) {
            $codes = DefaultRoles::codesFor($role);
            $this->actAs(self::TENANT_A, $codes);
            $this->send('PATCH', '/settings', ['display_name' => 'Renamed by '.$role]);
            if (\in_array('tenant_settings.edit', $codes, true)) {
                self::assertSame(200, $this->httpStatus(), $role.': '.$this->responseContent());
                $allowed[] = $role;
            } else {
                self::assertSame([403, 'tenant_settings.edit'], [$this->httpStatus(), $this->json()['required_permission'] ?? null], $role);
                $refused[] = $role;
            }
        }

        self::assertContains(DefaultRoles::ADMIN, $refused, 'Literal floor: admin does not hold the owner marker (E-15b).');
        self::assertSame([DefaultRoles::OWNER], $allowed, 'Literal floor: the owner marker is the owner\'s alone among the defaults.');
        $denials = $this->auditRowsOf(AuditEvent::AUTHZ_PERMISSION_DENIED);
        self::assertCount(\count($refused), $denials);
        self::assertSame(array_fill(0, \count($refused), 'tenant_settings.edit'), array_column($denials, 'permission_code'));
        self::assertCount(1, $this->auditRowsOf(AuditEvent::TENANT_SETTINGS_UPDATED), 'A refused rename wrote a row.');
    }

    #[Test]
    public function aBlankOverlongOrMalformedNameIsRefused(): void
    {
        $this->actAsOwner(self::TENANT_A);
        $before = $this->stored(self::TENANT_A);

        foreach ([
            'null' => [null, 'required'],
            'empty' => ['', 'blank'],
            'spaces only' => ["  \u{00A0}\t", 'blank'],
            'a number' => [42, 'not_a_string'],
            'a list' => [['Acme'], 'not_a_string'],
            '101 characters' => [str_repeat('a', 101), 'too_long'],
            'a zero-width space' => ["Acme\u{200B}Trading", 'invalid_character'],
            'a bidi override' => ["Acme\u{202E}gnidarT", 'invalid_character'],
            'a control character' => ["Acme\x07", 'invalid_character'],
        ] as $case => [$value, $code]) {
            $this->send('PATCH', '/settings', ['display_name' => $value]);
            self::assertSame(422, $this->httpStatus(), $case.': '.$this->responseContent());
            self::assertSame([['/display_name', $code]], array_map(static fn (array $e): array => [$e['pointer'], $e['code']], $this->json()['errors']), $case);
        }

        self::assertSame($before, $this->stored(self::TENANT_A), 'A refused rename changed the row.');
        self::assertSame([], $this->auditRowsOf(AuditEvent::TENANT_SETTINGS_UPDATED));
    }

    /** Unicode white space at both ends is trimmed before the CHECK; exactly 100 characters is accepted. */
    #[Test]
    public function theNameIsTrimmed(): void
    {
        $this->actAsOwner(self::TENANT_A);

        $this->send('PATCH', '/settings', ['display_name' => " \u{00A0}Acme Trading\u{3000}\n"]);
        self::assertSame(200, $this->httpStatus(), $this->responseContent());
        self::assertSame('Acme Trading', $this->stored(self::TENANT_A)['display_name']);
        self::assertSame('Acme Trading', json_decode($this->auditRowsOf(AuditEvent::TENANT_SETTINGS_UPDATED)[0]['metadata'], true, flags: \JSON_THROW_ON_ERROR)['to']);

        $hundred = str_repeat('é', 100);
        $this->send('PATCH', '/settings', ['display_name' => ' '.$hundred.' ']);
        self::assertSame(200, $this->httpStatus(), $this->responseContent());
        self::assertSame($hundred, $this->stored(self::TENANT_A)['display_name']);
    }

    /** RO-S1: the currency is read-only. Sent, it is refused as an undeclared field (API-9), and so is `workspace`. */
    #[Test]
    public function theCurrencyAndTheHandleCannotBeSent(): void
    {
        $this->actAsOwner(self::TENANT_A);
        $before = $this->stored(self::TENANT_A);

        foreach ([
            ['default_currency_code' => 'USD'],
            ['display_name' => 'Acme Trading', 'default_currency_code' => 'USD'],
            ['workspace' => 'acme'],
            ['id' => Uuid::v4()->toRfc4122()],
        ] as $body) {
            $this->send('PATCH', '/settings', $body);
            self::assertSame(422, $this->httpStatus(), json_encode($body).': '.$this->responseContent());
            self::assertContains('undeclared_field', array_column($this->json()['errors'], 'code'), json_encode($body));
        }

        self::assertSame($before, $this->stored(self::TENANT_A), 'A refused request changed the row.');
        self::assertSame('EUR', $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT default_currency_code FROM tenant_settings')[0]['default_currency_code']);
        self::assertSame([], $this->auditRowsOf(AuditEvent::TENANT_SETTINGS_UPDATED));
    }

    /** The same name (after trimming): 200, no UPDATE, no row, `updated_at` unchanged. */
    #[Test]
    public function aNoOpRenameWritesNothing(): void
    {
        $this->actAsOwner(self::TENANT_A);
        $before = $this->stored(self::TENANT_A);
        self::assertSame('Tenant A', $before['display_name'], 'Control: the fixture name is the registry\'s.');

        $recorder = $this->recorder();
        $recorder->start();
        foreach ([['display_name' => 'Tenant A'], ['display_name' => "  Tenant A\u{00A0}"]] as $body) {
            $this->send('PATCH', '/settings', $body);
            self::assertSame(200, $this->httpStatus(), $this->responseContent());
            self::assertSame('Tenant A', $this->json()['display_name']);
        }
        $recorder->stop();

        self::assertSame($before, $this->stored(self::TENANT_A), 'A rename that changed nothing moved the row.');
        self::assertSame([], $this->auditRowsOf(AuditEvent::TENANT_SETTINGS_UPDATED));
        self::assertSame([], $recorder->matching('/^\s*UPDATE\s+tenant_settings\b/i'), 'A no-op issued an UPDATE.');
        self::assertNotSame([], $recorder->matching('/FROM tenant_settings WHERE tenant_id = .* FOR NO KEY UPDATE/'), 'Control: the lock statement was not recorded.');
    }

    /** RO-S1: the registry is never written — not its name, not its `updated_at`, by no statement. */
    #[Test]
    public function aRenameNeverReachesTheRegistry(): void
    {
        $this->actAsOwner(self::TENANT_A);
        $registry = $this->owner()->fetchAssociative('SELECT name, slug, updated_at::text AS updated_at FROM tenant WHERE id = ?::uuid', [self::TENANT_A]);

        $recorder = $this->recorder();
        $recorder->start();
        $this->send('PATCH', '/settings', ['display_name' => 'Acme Trading']);
        $recorder->stop();

        self::assertSame(200, $this->httpStatus());
        self::assertSame($registry, $this->owner()->fetchAssociative('SELECT name, slug, updated_at::text AS updated_at FROM tenant WHERE id = ?::uuid', [self::TENANT_A]));
        self::assertSame([], $recorder->matching('/\b(UPDATE|INSERT\s+INTO|DELETE\s+FROM)\s+tenant\b(?!_)/i'), 'A statement wrote the registry.');
        self::assertNotSame([], $recorder->matching('/^\s*UPDATE\s+tenant_settings\b/i'), 'Control: the rename\'s own UPDATE was not recorded.');
    }

    /** Two workspaces, both callers: each renames its own, and neither sees nor moves the other's. */
    #[Test]
    public function anotherWorkspaceIsNeverRenamed(): void
    {
        $theirs = $this->stored(self::TENANT_B);
        $this->actAsOwner(self::TENANT_A);
        $this->send('PATCH', '/settings', ['display_name' => 'Alpha Renamed']);
        self::assertSame(200, $this->httpStatus());
        self::assertSame($theirs, $this->stored(self::TENANT_B), 'Tenant A\'s rename reached tenant B.');

        $this->actAsOwner(self::TENANT_B);
        $this->send('PATCH', '/settings', ['display_name' => 'Bravo Renamed']);
        self::assertSame(200, $this->httpStatus());
        self::assertSame('Bravo Renamed', $this->json()['display_name']);
        self::assertSame('Alpha Renamed', $this->stored(self::TENANT_A)['display_name'], 'Tenant B\'s rename reached tenant A.');

        $this->actAsOwner(self::TENANT_A);
        $this->send('GET', '/settings');
        self::assertSame('Alpha Renamed', $this->json()['display_name']);
        self::assertStringNotContainsString('Bravo', $this->responseContent(), 'Tenant B\'s rename is visible to tenant A.');
        self::assertCount(1, $this->auditRowsOf(AuditEvent::TENANT_SETTINGS_UPDATED, self::TENANT_A));
        self::assertCount(1, $this->auditRowsOf(AuditEvent::TENANT_SETTINGS_UPDATED, self::TENANT_B));
    }

    /** No settings row: the house's server fault (E4-3), and the writer never creates one (`erp_app` holds no INSERT). */
    #[Test]
    public function aWorkspaceWithoutASettingsRowIsAServerFault(): void
    {
        $this->insertAsOwnerForTenant(self::TENANT_A, 'DELETE FROM tenant_settings WHERE tenant_id = ?::uuid', [self::TENANT_A]);
        $this->actAsOwner(self::TENANT_A);

        $this->send('PATCH', '/settings', ['display_name' => 'Acme Trading']);

        self::assertSame(500, $this->httpStatus());
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM tenant_settings'));
        self::assertSame([], $this->auditRowsOf(AuditEvent::TENANT_SETTINGS_UPDATED));
    }

    /**
     * **F-M3: concurrent renames serialise.** A rename in flight (the holder: the lock, the UPDATE, its own audit row,
     * uncommitted) ∥ a second rename: the second waits on the settings row lock, then writes. Two rows, in commit order, and
     * the later row's `to` is the value stored. Mutation: drop `to` from the metadata → red here.
     */
    #[Test]
    public function concurrentRenamesSerialise(): void
    {
        $this->actAsOwner(self::TENANT_A);
        $settings = $this->stored(self::TENANT_A)['id'];

        $holder = $this->raceAgainstAHolder($this->holderRename($settings, 'First Name'), function (): void {
            $this->send('PATCH', '/settings', ['display_name' => 'Second Name']);
        });

        self::assertTrue($holder['waiter_seen'], 'The second rename never waited on the first. '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        self::assertContains('tenant_settings', array_column(array_filter($holder['waiter_locks'], static fn (array $l): bool => 'tuple' === $l['locktype']), 'relation'),
            'The request did not queue on the settings row: '.json_encode($holder['waiter_locks']));
        self::assertSame(200, $this->httpStatus(), $this->responseContent());

        $rows = $this->auditRowsOf(AuditEvent::TENANT_SETTINGS_UPDATED);
        self::assertSame(['First Name', 'Second Name'], array_map(static fn (array $r): string => json_decode($r['metadata'], true, flags: \JSON_THROW_ON_ERROR)['to'] ?? '', $rows), 'The rows are not in commit order.');
        self::assertSame('Second Name', $this->stored(self::TENANT_A)['display_name']);
        self::assertSame(['fields' => ['display_name'], 'to' => 'Second Name'], self::sortedKeys(json_decode($rows[1]['metadata'], true, flags: \JSON_THROW_ON_ERROR)));
    }

    /**
     * **F-M3: the no-op check sees the committed value.** A rename to "Same Name" in flight ∥ a second rename to "Same
     * Name": the second waits on the lock, then finds the name already set and writes nothing — one row (the holder's),
     * `updated_at` the holder's. Mutation: drop the lock (`FOR NO KEY UPDATE`) → the second reads the old name, writes a
     * second row → red here.
     */
    #[Test]
    public function aConcurrentRenameToTheValueJustCommittedWritesNothing(): void
    {
        $this->actAsOwner(self::TENANT_A);
        $settings = $this->stored(self::TENANT_A)['id'];

        $holder = $this->raceAgainstAHolder($this->holderRename($settings, 'Same Name'), function (): void {
            $this->send('PATCH', '/settings', ['display_name' => 'Same Name']);
        }, report: [[self::STAMPS, [self::TENANT_A]]]);

        self::assertTrue($holder['waiter_seen'], 'The second rename never waited on the first. '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        self::assertSame(200, $this->httpStatus(), $this->responseContent());
        self::assertCount(1, $this->auditRowsOf(AuditEvent::TENANT_SETTINGS_UPDATED), 'The second rename wrote a row for a name that was already set.');
        self::assertSame($holder['report'][0][0], $this->stored(self::TENANT_A), 'The second rename moved the row.');
    }

    // -----------------------------------------------------------------------

    /** The default owner role's codes: the rig's default actor is never an owner (`ApiTestCase::actAs()`). */
    private function actAsOwner(string $tenant): void
    {
        $this->actAs($tenant, DefaultRoles::codesFor(DefaultRoles::OWNER));
    }

    /** Read by the holder inside its own transaction, just before its COMMIT, and by the test afterwards. */
    private const STAMPS = 'SELECT id::text AS id, display_name, updated_at::text AS updated_at FROM tenant_settings WHERE tenant_id = ?::uuid';

    /** @return array{id: string, display_name: string, updated_at: string} */
    private function stored(string $tenant): array
    {
        /** @var array{id: string, display_name: string, updated_at: string} */
        return $this->fetchAsOwnerForTenant($tenant, self::STAMPS, [$tenant])[0];
    }

    /**
     * The holder's half: the application's own lock and UPDATE statements (never transcriptions), then the row the first
     * rename's writer would write.
     *
     * @return list<array{0: string, 1: list<string>}>
     */
    private function holderRename(string $settings, string $name): array
    {
        return [
            [SettingsWriter::LOCK, [self::TENANT_A]],
            [SettingsWriter::RENAME, [$name, $settings, self::TENANT_A]],
            [<<<'SQL'
                INSERT INTO audit_log (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label, subject_type, subject_id, ip_address, metadata)
                VALUES (?::uuid, ?::uuid, now(), 'tenant_settings.updated', ?::uuid, 'Holder', 'tenant_settings', ?::uuid, NULL, ?::jsonb)
                SQL, [Uuid::v4()->toRfc4122(), self::TENANT_A, (string) $this->actorId, $settings, json_encode(['fields' => ['display_name'], 'to' => $name], \JSON_THROW_ON_ERROR)]],
        ];
    }

    /**
     * @param array<string, mixed> $value
     *
     * @return array<string, mixed>
     */
    private static function sortedKeys(array $value): array
    {
        ksort($value);

        return $value;
    }

    /**
     * `ItemDeleteTest::raceAgainstAHolder`'s shape (a copy, not extracted, the earlier copies' reason): the holder's own lock
     * is on a random `sales_order` id, so it matches no row; the real locks are `HOLD_BEFORE`'s.
     *
     * @param list<array{0: string, 1: list<string>}> $before
     * @param list<array{0: string, 1: list<string>}> $report
     *
     * @return array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string, waiter_locks: list<array<string, mixed>>, report: list<list<array<string, mixed>>>}
     */
    private function raceAgainstAHolder(array $before, callable $request, array $report = []): array
    {
        $readyFile = tempnam(sys_get_temp_dir(), 'erp_settings_rename_');
        file_put_contents($readyFile, '');

        $proc = proc_open(
            [PHP_BINARY, __DIR__.'/support/hold_order_lock.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [
                'APP_DIRECT_DATABASE_URL' => $_ENV['APP_DIRECT_DATABASE_URL'],
                'HOLD_TENANT' => self::TENANT_A,
                'HOLD_ORDER' => Uuid::v4()->toRfc4122(),
                'HOLD_TABLE' => 'sales_order',
                'HOLD_BEFORE' => json_encode($before, \JSON_THROW_ON_ERROR),
                'HOLD_AFTER' => json_encode([], \JSON_THROW_ON_ERROR),
                'HOLD_REPORT' => json_encode($report, \JSON_THROW_ON_ERROR),
                'HOLD_READY_FILE' => $readyFile,
                'HOLD_DEADLINE_SECONDS' => (string) self::HOLDER_DEADLINE_SECONDS,
                'PATH' => (string) getenv('PATH'),
            ],
        );
        self::assertIsResource($proc);

        try {
            $deadline = microtime(true) + self::HOLDER_DEADLINE_SECONDS;
            while ('' === trim((string) file_get_contents($readyFile))) {
                if (microtime(true) > $deadline) {
                    self::fail('The holder never took the lock: '.stream_get_contents($pipes[2]));
                }
                usleep(1000);
            }
            if ('failed' === trim((string) file_get_contents($readyFile))) {
                self::fail('The holder failed before taking the lock: '.stream_get_contents($pipes[1]).stream_get_contents($pipes[2]));
            }

            $request();

            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
        } finally {
            if (proc_get_status($proc)['running']) {
                proc_terminate($proc);
            }
            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($proc);
            @unlink($readyFile);
        }

        $result = json_decode(trim($out), true);
        self::assertIsArray($result, 'The holder printed no report. stdout: '.$out.' stderr: '.$err);

        return $result;
    }
}
