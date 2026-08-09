<?php

namespace App\Services\Import;

/**
 * What one import run did, counted rather than logged.
 *
 * A bulk import that reports only "done" is one nobody can tell went wrong: the
 * failure mode is not an exception, it is 11,000 pages producing 400 records
 * because a heading changed. These counts are what makes that visible on the
 * command's own output.
 */
final class ImportSummary
{
    /** @var array<string, int> */
    private array $counts = [];

    /** @var list<string> */
    private array $problems = [];

    public function record(string $what, int $by = 1): void
    {
        $this->counts[$what] = ($this->counts[$what] ?? 0) + $by;
    }

    /**
     * Something the importer chose not to write, and why.
     *
     * Kept separate from the counts because these are the lines worth reading:
     * a page that yielded nothing, a row that collided with one already stored.
     */
    public function problem(string $description): void
    {
        $this->problems[] = $description;
        $this->record('skipped');
    }

    public function count(string $what): int
    {
        return $this->counts[$what] ?? 0;
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        ksort($this->counts);

        return $this->counts;
    }

    /**
     * @return list<string>
     */
    public function problems(): array
    {
        return $this->problems;
    }

    public function merge(self $other): void
    {
        foreach ($other->counts as $what => $by) {
            $this->record($what, $by);
        }

        // The skips are already counted in the merged totals above.
        $this->problems = [...$this->problems, ...$other->problems];
    }
}
