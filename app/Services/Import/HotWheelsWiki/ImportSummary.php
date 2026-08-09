<?php

namespace App\Services\Import\HotWheelsWiki;

/**
 * What one import run did, so a re-run can be shown to have changed nothing.
 */
class ImportSummary
{
    /** @var array<string, int> */
    private array $counts = [];

    /** @var list<string> */
    private array $skipped = [];

    public function created(string $type): void
    {
        $this->counts["{$type}.created"] = ($this->counts["{$type}.created"] ?? 0) + 1;
    }

    public function updated(string $type): void
    {
        $this->counts["{$type}.updated"] = ($this->counts["{$type}.updated"] ?? 0) + 1;
    }

    public function unchanged(string $type): void
    {
        $this->counts["{$type}.unchanged"] = ($this->counts["{$type}.unchanged"] ?? 0) + 1;
    }

    public function published(string $type): void
    {
        $this->counts["{$type}.published"] = ($this->counts["{$type}.published"] ?? 0) + 1;
    }

    public function skip(string $reason): void
    {
        $this->skipped[] = $reason;
    }

    public function count(string $key): int
    {
        return $this->counts[$key] ?? 0;
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
    public function skips(): array
    {
        return $this->skipped;
    }

    public function totalWritten(): int
    {
        $total = 0;

        foreach ($this->counts as $key => $count) {
            if (str_ends_with($key, '.created') || str_ends_with($key, '.updated')) {
                $total += $count;
            }
        }

        return $total;
    }
}
