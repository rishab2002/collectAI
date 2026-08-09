<?php

namespace App\Services\Import\HotWheelsWiki;

use App\Services\Import\Wikitext\Wikitext;
use App\Services\Import\Wikitext\WikitextTable;

/**
 * Reads a casting article into the facts the catalog stores.
 *
 * Two things on the page matter: the `{{casting}}` infobox, which names the
 * tool, and the `==Versions==` wikitable, whose rows are the releases and
 * variations. Everything else on the page — the description, the card text, the
 * trivia — is editor-written prose under CC-BY-SA and is never read.
 */
final class CastingPageParser
{
    /**
     * Table headers as they appear, mapped to the field they populate.
     *
     * Matched by name rather than by position because the tables disagree with
     * each other: the same column is headed "Notes" on one page and
     * "Notes / Variations" on the next, and pages add and drop columns freely.
     * A parser indexing by position reads the wrong column the first time an
     * editor inserts one.
     */
    private const COLUMNS = [
        'col #' => 'collector_number',
        'col.#' => 'collector_number',
        'col #.' => 'collector_number',
        'collector #' => 'collector_number',
        'year' => 'year',
        'series' => 'series',
        'color' => 'color',
        'colour' => 'color',
        'tampo' => 'tampo',
        'tampos' => 'tampo',
        'base color / type' => 'base',
        'base color/type' => 'base',
        'base color' => 'base',
        'base' => 'base',
        'window color' => 'window',
        'windows' => 'window',
        'interior color' => 'interior',
        'interior' => 'interior',
        'wheel type' => 'wheel',
        'wheels' => 'wheel',
        'toy #' => 'toy_number',
        'toy#' => 'toy_number',
        'country' => 'country',
    ];

    public function parse(string $wikitext, string $title, int $pageId, ?int $revisionId = null): CastingPage
    {
        $infobox = $this->infobox($wikitext);

        return new CastingPage(
            title: $title,
            pageId: $pageId,
            revisionId: $revisionId,
            // The infobox name is the canonical tool name; the page title is the
            // fallback, and is usually the same string.
            name: $infobox['name'] ?? Wikitext::plainText($title),
            designer: $infobox['designer'] ?? null,
            versions: $this->versions($wikitext),
        );
    }

    /**
     * The `{{casting|name=...|series=...}}` template's named parameters.
     *
     * Only `name` and `designer` are read. The infobox's `series` and `years`
     * summarise the Versions table and disagree with it often enough — a casting
     * "2011 - Present" whose table stops in 2019 — that trusting them over the
     * rows would import a fact the page itself contradicts.
     *
     * @return array<string, string>
     */
    private function infobox(string $wikitext): array
    {
        if (preg_match('/\{\{\s*casting\s*\|(.*?)\}\}/is', $wikitext, $matches) !== 1) {
            return [];
        }

        $fields = [];

        foreach ($this->splitParameters($matches[1]) as $parameter) {
            if (! str_contains($parameter, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $parameter, 2);
            $key = mb_strtolower(trim($key));
            $value = Wikitext::plainText($value);

            if ($key !== '' && $value !== '') {
                $fields[$key] = $value;
            }
        }

        return $fields;
    }

    /**
     * Splits template parameters on pipes that belong to the template itself,
     * not to a link inside one of its values — `designer = [[Jun Imai]] and
     * [[Guest Designers#Danicar|Danica Patrick]]` is one parameter.
     *
     * @return list<string>
     */
    private function splitParameters(string $body): array
    {
        $parameters = [];
        $buffer = '';
        $depth = 0;
        $length = strlen($body);

        for ($index = 0; $index < $length; $index++) {
            $pair = substr($body, $index, 2);

            if ($pair === '[[' || $pair === '{{') {
                $depth++;
                $buffer .= $pair;
                $index++;

                continue;
            }

            if ($pair === ']]' || $pair === '}}') {
                $depth = max(0, $depth - 1);
                $buffer .= $pair;
                $index++;

                continue;
            }

            if ($depth === 0 && $body[$index] === '|') {
                $parameters[] = $buffer;
                $buffer = '';

                continue;
            }

            $buffer .= $body[$index];
        }

        $parameters[] = $buffer;

        return $parameters;
    }

    /**
     * @return list<VersionRow>
     */
    private function versions(string $wikitext): array
    {
        $section = $this->versionsSection($wikitext);

        if ($section === null) {
            return [];
        }

        $table = WikitextTable::firstIn($section);

        if ($table === null) {
            return [];
        }

        $versions = [];
        $line = 0;

        foreach ($table->keyedRows() as $row) {
            $versions[] = $this->version(++$line, $this->mapColumns($row));
        }

        return $versions;
    }

    /**
     * The `==Versions==` section only, so a page whose first table is something
     * else — a card-art gallery, a series listing — doesn't get read as one.
     */
    private function versionsSection(string $wikitext): ?string
    {
        if (preg_match('/^(==+)\s*Versions\s*\1\s*$/mi', $wikitext, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $start = $matches[0][1] + strlen($matches[0][0]);
        $rest = substr($wikitext, $start);
        $level = strlen($matches[1][0]);

        // Stops at the next heading of the same level or higher, so a
        // `===Variations===` subsection stays part of Versions.
        if (preg_match('/^={1,'.$level.'}\s*[^=].*?={1,'.$level.'}\s*$/m', $rest, $next, PREG_OFFSET_CAPTURE) === 1) {
            return substr($rest, 0, $next[0][1]);
        }

        return $rest;
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, string>
     */
    private function mapColumns(array $row): array
    {
        $mapped = [];

        foreach ($row as $header => $cell) {
            $normalized = trim(preg_replace('/\s*\/\s*/', ' / ', $header) ?? $header);
            $field = self::COLUMNS[$normalized] ?? self::COLUMNS[str_replace(' ', '', $normalized)] ?? null;

            // An unrecognised header is dropped on purpose. Notes and Photo land
            // here, and so does any column an editor invents — which is the
            // right default when the alternative is importing prose.
            if ($field !== null && ! array_key_exists($field, $mapped)) {
                $mapped[$field] = $cell;
            }
        }

        return $mapped;
    }

    /**
     * @param  array<string, string>  $cells
     */
    private function version(int $line, array $cells): VersionRow
    {
        [$seriesName, $seriesPage, $seriesPosition] = $this->series($cells['series'] ?? '');
        [$baseColor, $baseType] = $this->base($cells['base'] ?? '');

        return new VersionRow(
            line: $line,
            year: $this->year($cells['year'] ?? ''),
            // "002/244" is this car's number within the year's whole run. Only
            // the left half identifies it; the right is the run size, which is a
            // fact about the year and not about this release.
            collectorNumber: $this->collectorNumber($cells['collector_number'] ?? ''),
            seriesName: $seriesName,
            seriesPage: $seriesPage,
            seriesPosition: $seriesPosition,
            color: Wikitext::valueOrNull($cells['color'] ?? ''),
            tampo: Wikitext::valueOrNull($cells['tampo'] ?? ''),
            baseColor: $baseColor,
            baseType: $baseType,
            windowColor: Wikitext::valueOrNull($cells['window'] ?? ''),
            interiorColor: Wikitext::valueOrNull($cells['interior'] ?? ''),
            wheelType: Wikitext::valueOrNull($cells['wheel'] ?? ''),
            toyNumber: Wikitext::valueOrNull($cells['toy_number'] ?? ''),
            country: Wikitext::valueOrNull($cells['country'] ?? ''),
        );
    }

    /**
     * The Year cell is a link to that year's index — "[[List of 2011 Hot
     * Wheels|2011]]" or plain "[[2011]]" — so the year is whatever four-digit
     * number the reader ends up seeing.
     */
    private function year(string $cell): ?int
    {
        if (preg_match('/\b(19|20)\d{2}\b/', Wikitext::plainText($cell), $matches) !== 1) {
            return null;
        }

        return (int) $matches[0];
    }

    private function collectorNumber(string $cell): ?string
    {
        $value = Wikitext::plainText($cell);

        if ($value === '') {
            return null;
        }

        $number = trim(explode('/', $value)[0]);

        return $number === '' ? null : $number;
    }

    /**
     * The Series cell stacks two facts: a link to the series, and this car's
     * position within it — "[[2011 New Models]]<br>2/50".
     *
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    private function series(string $cell): array
    {
        $parts = Wikitext::splitOnBreaks($cell);

        if ($parts === []) {
            return [null, null, null];
        }

        $link = Wikitext::firstLink($parts[0]);
        $name = $link !== null ? $link[1] : Wikitext::valueOrNull($parts[0]);
        $page = $link !== null ? $link[0] : null;

        // The position is only the second half when it reads like one; some
        // pages put a second series link there instead.
        $position = null;

        if (isset($parts[1]) && preg_match('#^\d+\s*/\s*\d+$#', Wikitext::plainText($parts[1])) === 1) {
            $position = Wikitext::plainText($parts[1]);
        }

        return [$name, $page, $position];
    }

    /**
     * "Green<br>/<br>Plastic" is a colour and a material in one cell.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function base(string $cell): array
    {
        $parts = array_values(array_filter(
            Wikitext::splitOnBreaks($cell),
            static fn (string $part): bool => trim($part) !== '/',
        ));

        $color = isset($parts[0]) ? Wikitext::valueOrNull($parts[0]) : null;
        $type = isset($parts[1]) ? Wikitext::valueOrNull($parts[1]) : null;

        // A cell that wasn't stacked at all still separates the two with a
        // slash, and splitting on it is what keeps "Black" from becoming
        // "Black / Plastic" — two spellings of one variation.
        if ($type === null && $color !== null && str_contains($color, '/')) {
            [$color, $type] = array_map(trim(...), explode('/', $color, 2));
        }

        return [$color ?: null, $type ?: null];
    }
}
