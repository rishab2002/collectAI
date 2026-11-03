<?php

namespace App\Http\Requests\Concerns;

/**
 * The release and variation fields a hand-entered car is described by.
 *
 * Release: which brand sold it, under which series, in which year. Variation:
 * the columns that make one unit distinct, which are exactly
 * `Variation::DISTINGUISHING_ATTRIBUTES` plus free-text notes. Lengths match
 * the columns, so an over-long value is a message rather than a 500 from
 * Postgres.
 */
trait DescribesAVariation
{
    /**
     * @return array<string, mixed>
     */
    protected function variationRules(): array
    {
        $short = ['nullable', 'string', 'max:255'];

        return [
            'brand' => ['required', 'string', 'max:255'],
            'series' => ['required', 'string', 'max:255'],
            // The main line the series sits under ("Car Culture"), used only
            // when the series is new; an existing series keeps its placement.
            'line' => $short,
            // Picked with "find the model": links the new record to that
            // reference product so the visual model recognises it.
            'reference_item_id' => ['nullable', 'integer', 'exists:reference_items,id'],
            // Matches releases_year_range.
            'year' => ['required', 'integer', 'between:1900,2100'],
            'collector_number' => $short,

            'color' => $short,
            'finish' => $short,
            'rim_type' => $short,
            // `variations.tampo` is text: a convention car's runs to hundreds of
            // characters, and it is part of the uniqueness index, so cutting it
            // short would change which cars count as the same car.
            'tampo' => ['nullable', 'string', 'max:2000'],
            'interior_color' => $short,
            'window_tint' => $short,
            'base_color' => $short,
            'toy_number' => $short,
            'country' => $short,
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function variationAttributes(): array
    {
        return [
            'rim_type' => 'wheel type',
            'interior_color' => 'interior colour',
            'window_tint' => 'window tint',
            'base_color' => 'base',
            'toy_number' => 'toy number',
            'collector_number' => 'collector number',
        ];
    }
}
