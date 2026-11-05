<?php

namespace App\Http\Requests;

/**
 * A car added from the scan that found it.
 *
 * Exactly `StoreCarRequest`'s rules — there is one set of rules for entering a
 * car by hand and this is not a second — with the series fields named the way
 * a collector reads a card rather than the way the schema stores them.
 *
 * On a card you read a line and, under it, a wave: "Mainline", then "Factory
 * Fresh". In the schema both are `series` rows, the wave pointing at the line
 * through `parent_id`, and `ManualCatalogEntry` takes them as `series` (the
 * row the release hangs on) and `line` (its parent). So:
 *
 * - **Series** is the line — `line`.
 * - **Sub-series** is the wave under it — `series` — and is optional, because
 *   plenty of cars are sold in a line with no wave inside it. Left empty, the
 *   release hangs on the line itself, which is what `ManualCatalogEntry`
 *   already does when the two names match.
 */
class StoreScanCarRequest extends StoreCarRequest
{
    protected function prepareForValidation(): void
    {
        $line = trim((string) $this->input('series'));
        $sub = trim((string) $this->input('sub_series'));

        $this->merge([
            'line' => $line,
            'series' => $sub !== '' ? $sub : $line,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            ...parent::attributes(),
            // Named for what the admin typed into, not for where it lands.
            'line' => 'series',
            'series' => 'series',
        ];
    }
}
