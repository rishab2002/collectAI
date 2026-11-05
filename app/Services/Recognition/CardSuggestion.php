<?php

namespace App\Services\Recognition;

use App\Models\Make;
use App\Models\ScanDetection;
use Illuminate\Support\Str;

/**
 * What a scan detection can fill in on the "add this car" form.
 *
 * Every value here is a starting point the admin corrects, never a decision:
 * the form is the thing that writes to the catalog, and it validates exactly
 * as it does on the Add a car page. The job is only to save retyping a card
 * that has already been read once.
 *
 * What it deliberately does not offer:
 *
 * - **The model.** The printed name is the casting name — "Porsche 911
 *   Carrera T" — and `models.name` wants the nameplate alone, "911". Guessing
 *   the split is the Metadata Agent's job, and getting it wrong here mints the
 *   trim-named near-duplicate rows that CLAUDE.md already has a backlog of.
 *   The make is offered because it is exact: the first words of the name,
 *   matched against `makes.name`, which is hard-unique.
 * - **The colour.** A sealed package shows its artwork, not its car, so the
 *   packaged scan reports no colour at all and inventing one here would
 *   undo that.
 * - **The series.** It is printed on the card, but the reader does not pick it
 *   out yet, and a wrong series files the car under a wave it was never in.
 */
final class CardSuggestion
{
    /**
     * @return array<string, string|null>
     */
    public static function from(ScanDetection $detection): array
    {
        $reading = $detection->reading ?? [];
        $card = $reading['card'] ?? [];
        $name = self::asCatalogWritesIt((string) ($reading['headline'] ?? ''));

        return [
            'brand' => $card['brand'] ?? $reading['brand'] ?? null,
            'casting_name' => $name,
            'make' => self::makeIn($name),
            'year' => isset($reading['copyright_year']) ? (string) $reading['copyright_year'] : null,
            'collector_number' => $card['collector_number'] ?? null,
            'toy_number' => $card['toy_number'] ?? null,
        ];
    }

    /**
     * "PORSCHE 911 CARRERA T" as "Porsche 911 Carrera T".
     *
     * Cards are printed in capitals and the catalog is not. A word is left as
     * printed when it has a digit in it or is three characters or fewer, which
     * is what keeps RX-7, STI, SRT, GTO and the trailing T of a Carrera T from
     * becoming Rx-7, Sti, Srt, Gto and t.
     */
    public static function asCatalogWritesIt(string $printed): ?string
    {
        $words = preg_split('/\s+/', trim($printed), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return null;
        }

        return collect($words)
            ->map(fn (string $word): string => preg_match('/\d/', $word) || mb_strlen($word) <= 3
                ? $word
                : Str::ucfirst(Str::lower($word)))
            ->implode(' ');
    }

    /**
     * The make the name starts with, if the catalog already knows it.
     *
     * Longest first, so "Alfa Romeo" wins over a hypothetical "Alfa", and
     * exact — a fuzzy hit here would file a Ford GT under Ferrari.
     */
    private static function makeIn(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $lower = Str::lower($name);

        return Make::query()->pluck('name')
            ->sortByDesc(fn (string $make): int => mb_strlen($make))
            ->first(fn (string $make): bool => str_starts_with($lower, Str::lower($make).' '));
    }
}
