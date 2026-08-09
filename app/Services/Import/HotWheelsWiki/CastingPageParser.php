<?php

namespace App\Services\Import\HotWheelsWiki;

use App\Services\Import\WikitableParser;
use App\Services\Import\Wikitext;

/**
 * Reads a casting article into the records it states.
 *
 * Only the {{casting}} infobox and the ==Versions== wikitable are touched. The
 * Description prose, the Notes column and the images are all deliberately left
 * behind: they are the parts of the wiki that are actually authored, and the
 * settled import rule takes facts only — which is both what keeps this an
 * original database rather than a mirror and what keeps it clear of CC-BY-SA
 * obligations beyond the attribution record_sources already carries.
 */
class CastingPageParser
{
    /**
     * Headings that identify the versions table, lowercased. Casting pages are
     * inconsistent about whether the table is the page's first, so it is found
     * by its columns rather than by position.
     */
    private const REQUIRED_HEADINGS = ['year', 'series'];

    public function parse(
        string $wikitext,
        int $pageId,
        string $title,
        string $url,
        ?int $revisionId = null,
    ): ParsedCasting {
        return new ParsedCasting(
            name: $this->castingName($wikitext, $title),
            pageId: $pageId,
            title: $title,
            url: $url,
            revisionId: $revisionId,
            releases: $this->releases($wikitext),
        );
    }

    /**
     * The name from the {{casting}} infobox, falling back to the page title.
     *
     * The infobox is preferred because a page title carries disambiguation the
     * tool's name doesn't — "Honda Civic Si (2001)" is one article about a
     * casting called "Honda Civic Si".
     */
    public function castingName(string $wikitext, string $title): string
    {
        if (preg_match('/\{\{\s*casting\b(.*?)\}\}/isu', $wikitext, $matches) === 1
            && preg_match('/\|\s*name\s*=\s*([^|}\n]+)/u', $matches[1], $name) === 1) {
            $parsed = Wikitext::nullIfBlank(Wikitext::plain($name[1]));

            if ($parsed !== null) {
                return $parsed;
            }
        }

        return Wikitext::tidy($title);
    }

    /**
     * @return list<ParsedRelease>
     */
    public function releases(string $wikitext): array
    {
        $rows = $this->versionsRows($wikitext);

        if ($rows === []) {
            return [];
        }

        /** @var array<string, array{release: ParsedRelease, variations: list<ParsedVariation>}> $grouped */
        $grouped = [];

        foreach ($rows as $row) {
            $year = $this->year($row);
            $series = $this->series($row);

            // A row the source hasn't dated or filed can't become a release: the
            // releases table keys on year and series and both are non-nullable.
            // Skipped rather than guessed at — the year index will usually carry
            // the same car, and a wrong year is worse than a missing row.
            if ($year === null || $series['name'] === null) {
                continue;
            }

            $key = $year.'|'.($series['target'] ?? $series['name']);

            $grouped[$key] ??= [
                'release' => new ParsedRelease(
                    year: $year,
                    seriesName: $series['name'],
                    seriesTarget: $series['target'],
                    seriesPosition: $series['position'],
                    collectorNumber: $this->collectorNumber($row),
                ),
                'variations' => [],
            ];

            $variation = $this->variation($row);

            // Two identical rows describe one variation — the uniqueness index
            // would reject the second anyway, and rejecting it here keeps the
            // per-release variation numbering (and so the source keys) stable.
            foreach ($grouped[$key]['variations'] as $existing) {
                if ($existing->signature() === $variation->signature()) {
                    continue 2;
                }
            }

            $grouped[$key]['variations'][] = $variation;
        }

        return array_values(array_map(
            fn (array $group): ParsedRelease => $group['release']->withVariations($group['variations']),
            $grouped,
        ));
    }

    /**
     * Rows from *every* versions table on the page, not just the first.
     *
     * Casting pages routinely carry more than one — a mainline table and a
     * separate one for international or later releases — and they are not
     * ordered by year, so the first is not the interesting one. Reading only the
     * first dropped the 2011 XS-IVE release, which sits in the page's second
     * table, and did it silently: the casting simply looked like it had no 2011
     * release at all.
     *
     * Rows are keyed by heading, so tables whose columns differ slightly merge
     * without their values sliding into the wrong fields.
     *
     * @return list<array<string, string>>
     */
    private function versionsRows(string $wikitext): array
    {
        $rows = [];

        foreach (WikitableParser::parseAll($wikitext) as $table) {
            if ($table === [] || ! $this->isVersionsTable($table)) {
                continue;
            }

            foreach ($table as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  list<array<string, string>>  $table
     */
    private function isVersionsTable(array $table): bool
    {
        $headings = array_map($this->normaliseHeading(...), array_keys($table[0]));

        foreach (self::REQUIRED_HEADINGS as $required) {
            if (! in_array($required, $headings, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The year cell is usually a link to that year's index —
     * "[[List of 2006 Hot Wheels|2006]]" — so the four digits are taken from
     * wherever they appear rather than by parsing the whole cell as a number.
     */
    private function year(array $row): ?int
    {
        $cell = Wikitext::plain($this->cell($row, 'Year') ?? '');

        return preg_match('/\b(19|20)\d{2}\b/', $cell, $matches) === 1
            ? (int) $matches[0]
            : null;
    }

    /**
     * Splits the series cell into the series itself and its position.
     *
     * Two shapes appear, both of which stack values with <br>:
     * "[[2006 First Editions]]<br>19/38" is a series and the car's place in it,
     * and "Nightburnerz<br />'''Kmart Exclusive'''" is a series and a retail
     * note. The position is recognised by looking like one, so the note doesn't
     * become one.
     *
     * @return array{name: string|null, target: string|null, position: string|null}
     */
    private function series(array $row): array
    {
        $cell = $this->cell($row, 'Series') ?? '';
        $lines = Wikitext::lines($cell);

        $name = null;
        $position = null;

        foreach ($lines as $line) {
            $plain = Wikitext::plain($line);

            if (preg_match('/^\d+\s*\/\s*\d+$/', $plain) === 1) {
                $position ??= Wikitext::tidy($plain);

                continue;
            }

            $name ??= Wikitext::nullIfBlank($plain);
        }

        return [
            'name' => $name,
            'target' => Wikitext::linkTarget($cell),
            'position' => $position,
        ];
    }

    /**
     * "Col #" arrives either bare ("019") or as a position in the year's whole
     * run ("032/244"). Only the car's own number is kept — the total is a fact
     * about the year, not about this release, and storing "032/244" would make
     * the column disagree with itself across pages.
     */
    private function collectorNumber(array $row): ?string
    {
        $cell = Wikitext::plain($this->cell($row, 'Col #') ?? $this->cell($row, 'Col.#') ?? '');
        $cell = Wikitext::tidy($cell);

        if ($cell === '') {
            return null;
        }

        return Wikitext::nullIfBlank(explode('/', $cell)[0]);
    }

    private function variation(array $row): ParsedVariation
    {
        return new ParsedVariation(
            color: $this->plainCell($row, 'Color'),
            tampo: $this->plainCell($row, 'Tampos') ?? $this->plainCell($row, 'Tampo'),
            baseColor: $this->baseColor($row),
            windowTint: $this->plainCell($row, 'Window Color') ?? $this->plainCell($row, 'Window Tint'),
            // Some pages label the column "Interior / Engine Color", which is
            // the same fact about the same part of the car under a wordier
            // heading — matched explicitly rather than by prefix, so a genuinely
            // different column can't quietly land in interior_color.
            interiorColor: $this->plainCell($row, 'Interior Color')
                ?? $this->plainCell($row, 'Interior / Engine Color')
                ?? $this->plainCell($row, 'Interior'),
            rimType: $this->plainCell($row, 'Wheel Type'),
            toyNumber: $this->plainCell($row, 'Toy #'),
        );
    }

    /**
     * "Chrome<br>/<br>Plastic" is a base colour and the material it's made of,
     * under a heading that names both. Only the colour is kept, because that is
     * the column that exists; the material is a fact this schema currently has
     * nowhere to put, and padding it into base_color would make the value
     * disagree with the same value read off a page that omits the material.
     */
    private function baseColor(array $row): ?string
    {
        $cell = $this->cell($row, 'Base Color/Type')
            ?? $this->cell($row, 'Base Color / Type')
            ?? $this->cell($row, 'Base Color')
            ?? $this->cell($row, 'Base');

        if ($cell === null) {
            return null;
        }

        $lines = Wikitext::lines($cell);

        return Wikitext::nullIfBlank(Wikitext::plain($lines[0] ?? ''));
    }

    private function plainCell(array $row, string $heading): ?string
    {
        $cell = $this->cell($row, $heading);

        return $cell === null ? null : Wikitext::nullIfBlank(Wikitext::plain($cell));
    }

    /**
     * Headings vary in case and spacing between pages, so they're matched
     * loosely rather than exactly.
     */
    private function cell(array $row, string $heading): ?string
    {
        $wanted = $this->normaliseHeading($heading);

        foreach ($row as $key => $value) {
            if ($this->normaliseHeading($key) === $wanted) {
                return $value;
            }
        }

        return null;
    }

    private function normaliseHeading(string $heading): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $heading) ?? $heading);
    }
}
