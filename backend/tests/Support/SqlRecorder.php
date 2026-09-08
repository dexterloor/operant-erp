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

    private bool $recording = false;

    public function start(): void
    {
        $this->statements = [];
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
