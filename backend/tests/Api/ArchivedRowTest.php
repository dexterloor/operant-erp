<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\HttpOperation;
use PHPUnit\Framework\Attributes\Test;

/**
 * API-13a and TB-3 (`docs/API.md` §5.2a, which §4.4.4 defers to) — an archived
 * row is read, not refused; it never leaks into a live list; and it is
 * read-only.
 *
 * The domain — `SchemaManifest::TABLES`' `soft` entries restricted to built
 * paths (owner decision (e) under ruling A), each clause's subject operations,
 * the per-table fixtures and clause 15's driven requests — is derived once in
 * `ArchivedRowSubjects`, with its measured exclusions. It moved there in V4 B5
 * (V1-C1a), when `ErrorContractTest` half 2 began reading clause 15's answers as
 * its fourth probe, so the two tests share one derivation instead of copies.
 */
final class ArchivedRowTest extends PurchaseOrderTestCase
{
    use ArchivedRowSubjects;

    private const BUSINESS_RULE = 'urn:erp:problem:business-rule-violated';

    /**
     * **API-13a — a `GET` on a soft-deleted row returns 200 with a non-null
     * `archived_at`, never 404.** Control inside: the live twin, read the same
     * way, carries a null `archived_at`, so the field is not constant.
     *
     * **Mutation (§5.2a's, C5 batch B, 2026-09-14; cache deleted — Rule 17):**
     * the soft-delete predicate applied to the direct-hit query; red, 404.
     * Recorded in `docs/session9/progress-C5B.md` row 13.
     */
    #[Test]
    public function aSoftDeletedRowIsReadableByDirectHit(): void
    {
        $subjects = $this->archivedRowSubjects(static fn (string $verb, HttpOperation $operation): bool => 'Get' === $verb);

        // Every fixture before the first request: a refused write closes the
        // entity manager the seeding helpers run through.
        $pairs = $this->archivedRowPairs($subjects);

        $defects = [];
        foreach ($subjects as $table => $operations) {
            foreach ($operations as $name => $operation) {
                [$live, $archived] = $pairs[$name];

                $this->send('GET', self::archivedRowPath($operation, $live));
                self::assertSame(200, $this->httpStatus(), $name.' did not read the live twin.');
                $body = $this->json();
                self::assertTrue(\array_key_exists('archived_at', $body) && null === $body['archived_at'],
                    $name.'\'s live twin does not carry a null archived_at, so the check below proves nothing.');

                $this->send('GET', self::archivedRowPath($operation, $archived));
                if (200 !== $this->httpStatus()) {
                    $defects[] = \sprintf('%s on an archived %s row answered %d', $name, $table, $this->httpStatus());
                } elseif (null === ($this->json()['archived_at'] ?? null)) {
                    $defects[] = \sprintf('%s on an archived %s row returned a null or absent archived_at', $name, $table);
                }
            }
        }

        self::assertSame([], $defects, 'API-13a: an archived row is not readable by direct hit.');
    }

    /**
     * **TB-3 — no list returns a soft-deleted row when `archived` is absent or
     * false.** Each list is read with no `archived` parameter and, where the list
     * declares one, with `archived=false`. Control inside: the live twin is on
     * every one of those pages.
     *
     * **Mutation (§5.2a's second, C5 batch B, 2026-09-14; cache deleted — Rule
     * 17):** the soft-delete predicate dropped from a list query; red, naming
     * it. Recorded in `docs/session9/progress-C5B.md` row 14.
     */
    #[Test]
    public function aSoftDeletedRowIsAbsentFromEveryLiveList(): void
    {
        $subjects = $this->archivedRowSubjects(static fn (string $verb, HttpOperation $operation): bool => 'List' === $verb);

        // Every fixture before the first request: a refused write closes the
        // entity manager the seeding helpers run through.
        $pairs = $this->archivedRowPairs($subjects);

        $defects = [];
        foreach ($subjects as $table => $operations) {
            foreach ($operations as $name => $operation) {
                [$live, $archived] = $pairs[$name];
                $queries = [''];
                if ((bool) $operation->getParameters()?->has('archived')) {
                    $queries[] = '?archived=false';
                }

                foreach ($queries as $query) {
                    $this->send('GET', self::archivedRowPath($operation, $live).$query);
                    self::assertSame(200, $this->httpStatus(), $name.$query.' did not answer.');
                    $ids = array_column($this->json()['items'] ?? [], 'id');

                    self::assertContains($live['row'], $ids, $name.$query.' does not list the live twin, so an absent archived row proves nothing.');
                    if (\in_array($archived['row'], $ids, true)) {
                        $defects[] = \sprintf('%s%s returns an archived %s row', $name, $query, $table);
                    }
                }
            }
        }

        self::assertSame([], $defects, 'TB-3: a live list returns a soft-deleted row.');
    }

    /**
     * **ST-9 — a `PATCH`, `DELETE` or transition on a soft-deleted row is
     * refused as `business-rule-violated`.** Control inside: the same request on
     * the live twin succeeds, so the refusal is the archived state's and not the
     * request's.
     *
     * **Mutation (C5 batch B, 2026-09-14; cache deleted — Rule 17):** the
     * archived guard removed from one writer; red, naming the operation.
     * Recorded in `docs/session9/progress-C5B.md` row 15.
     *
     * The domain, bodies, live-twin control and requests are
     * `ArchivedRowSubjects::archivedRowWriteAnswers()`; since V4 B5 the same
     * answers are `ErrorContractTest` half 2's fourth probe (V1-C1a), which
     * judges them against §4.2's `Errors` cells instead of ST-9.
     */
    #[Test]
    public function aSoftDeletedRowRefusesEveryWrite(): void
    {
        $defects = [];
        foreach ($this->archivedRowWriteAnswers() as $name => $answer) {
            $type = json_decode($answer['content'], true)['type'] ?? null;
            if (self::BUSINESS_RULE !== $type) {
                $defects[] = \sprintf('%s on an archived %s row answered %d %s', $name, $answer['table'], $answer['status'], substr($answer['content'], 0, 200));
            }
        }

        self::assertSame([], $defects, 'ST-9: a write on a soft-deleted row was not refused as a business rule.');
    }
}
