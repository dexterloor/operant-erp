<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Every statement the application sends, in order.
 *
 * Several of this contract's rules are properties of the SQL rather than of the
 * response -- API-17's "no list query emits OFFSET", TB-5's "a search touches
 * only the columns TB-5 names", API-22's "the empty probe runs only on an empty
 * page", P-7's "serializing issues no further query". None of them is decidable
 * from a response body, and all four are the kind of thing a plausible
 * refactor breaks silently.
 */
final class SqlRecorder
{
    /** @var list<string> */
    private array $statements = [];

    /** @var list<array{sql: string, params: array<int|string, mixed>}> */
    private array $executions = [];

    private bool $recording = false;

    public function start(): void
    {
        $this->statements = [];
        $this->executions = [];
        $this->recording = true;
    }

    public function stop(): void
    {
        $this->recording = false;
    }

    public function record(string $sql): void
    {
        if ($this->recording) {
            $this->statements[] = $sql;
        }
    }

    /**
     * A prepared statement's execution with the values bound to it, in the
     * order `recordExecution()` saw them. Recorded only while recording, like
     * `record()`.
     *
     * @param array<int|string, mixed> $params
     */
    public function recordExecution(string $sql, array $params): void
    {
        if ($this->recording) {
            $this->executions[] = ['sql' => $sql, 'params' => $params];
        }
    }

    /** @return list<array{sql: string, params: array<int|string, mixed>}> */
    public function executions(): array
    {
        return $this->executions;
    }

    /** @return list<string> */
    public function statements(): array
    {
        return $this->statements;
    }

    /**
     * @return list<string> statements whose text matches, for the assertion to
     *                      report rather than a bare count
     */
    public function matching(string $pattern): array
    {
        return array_values(array_filter($this->statements, static fn (string $sql): bool => 1 === preg_match($pattern, $sql)));
    }
}
