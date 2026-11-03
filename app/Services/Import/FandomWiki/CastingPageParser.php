<?php

namespace App\Services\Import\FandomWiki;

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
        'base color / types' => 'base',
        'toy #' => 'toy_number',
        'toy#' => 'toy_number',
        // The Matchbox wiki's spellings. A car sold under two regional toy
        // numbers lists both in the one cell.
        'toy number(s)' => 'toy_number',
        'toy numbers' => 'toy_number',
        'toy number' => 'toy_number',
        'wheel / type' => 'wheel',
        // Older Matchbox models were labelled or decal-printed rather than
        // tampo-printed. Same fact — the printed graphics — so the same column.
        'decals' => 'tampo',
        'labels' => 'tampo',
        'country' => 'country',
    ];

    /**
     * Version sections that aren't Mattel releases.
     *
     * "Code 3" is the collector term for a car a third party has repainted or
     * rebuilt after it left the factory. The wiki lists them under their own
     * heading on the casting's page, but they are not something Hot Wheels ever
     * sold, and importing them would put customs in a catalog of retail
     * releases.
     */
    private const EXCLUDED_SECTIONS = ['code 3'];

    private readonly Wiki $wiki;

    public function __construct(?Wiki $wiki = null)
    {
        $this->wiki = $wiki ?? Wiki::hotWheels();
    }

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
            wiki: $this->wiki,
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
        $versions = [];

        // Numbered across the page, not per section: the line number is what a
        // row's provenance key is built from, and restarting it in a second
        // section would give two different cars the same key.
        $line = 0;

        foreach ($this->versionSections($wikitext) as $label => $section) {
            $table = WikitextTable::firstIn($section);

            if ($table === null) {
                continue;
            }

            foreach ($table->keyedRows() as $row) {
                $versions[] = $this->version(++$line, $label ?: null, $this->mapColumns($row));
            }
        }

        return $versions;
    }

    /**
     * Every section whose heading ends in "Versions", keyed by the tooling it
     * names — "" for a plain `==Versions==`.
     *
     * Scoped to those headings so a page whose first table is something else — a
     * card-art gallery, a series listing — doesn't get read as a version table.
     *
     * Most pages have exactly one. The ones that don't are the reason this
     * returns a list: `'57 Chevy` files its releases under "Closed Hood
     * Versions" and "Exposed Engine Versions", which is the wiki describing two
     * physical tools, and taking only the first table would drop half the page
     * while taking neither would drop all of it.
     *
     * @return array<string, string>
     */
    private function versionSections(string $wikitext): array
    {
        // Every heading, then filtered on its plain text. Matching "Versions" in
        // the raw markup missed every heading written in bold —
        // `=='''USA Versions'''==` — which is how the wiki writes most of the
        // 1968–69 castings, so Python, Chaparral 2G and the rest parsed to no
        // rows and were reported as pages with nothing to import.
        preg_match_all('/^(==+)\s*(.*?)\s*\1\s*$/m', $wikitext, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $sections = [];

        foreach ($matches as $match) {
            [$heading, $offset] = $match[0];
            $level = strlen($match[1][0]);
            $label = Wikitext::plainText($match[2][0]);

            // "Versions", and also a singular "Version" and a trailing
            // parenthetical — "Original Versions (rear loading)", Matchbox's
            // "Versions (MB168)". Widened 2026-11, before any full import had
            // run, after checking it renumbered no row that existed anywhere.
            // From here on a change to which headings count can shift the row
            // numbers provenance keys are built from: measure that first.
            if (preg_match('/(?:^|\s)Versions?(?:\s*\([^)]*\))?$/i', $label) !== 1) {
                continue;
            }

            // "Versions" itself carries no tooling name; anything around it does.
            $label = trim(preg_replace('/(?:^|\s+)Versions?(?=\s*\(|$)/i', '', $label) ?? '');

            if (preg_match('/^\((.*)\)$/', $label, $bare) === 1) {
                $label = trim($bare[1]);
            }

            if (in_array(mb_strtolower($label), self::EXCLUDED_SECTIONS, true)) {
                continue;
            }

            $rest = substr($wikitext, $offset + strlen($heading));

            // Stops at the next heading of the same level or higher, so a
            // `===Variations===` subsection stays part of its Versions section.
            if (preg_match('/^={1,'.$level.'}\s*[^=].*?={1,'.$level.'}\s*$/m', $rest, $next, PREG_OFFSET_CAPTURE) === 1) {
                $rest = substr($rest, 0, $next[0][1]);
            }

            // A page with both "Versions" and "Custom Versions" keys them apart;
            // a page repeating a heading keeps the first.
            $sections[$label] ??= $rest;
        }

        return $sections;
    }

    /**
     * The article a redirect points at, or null if this page isn't one.
     *
     * Year indexes link to redirects often enough to matter — three of the first
     * sixty 2011 links are one — and a redirect parses as a page with no
     * versions, so without this the casting is skipped in silence rather than
     * followed.
     */
    public static function redirectTarget(string $wikitext): ?string
    {
        // Only the top of the page counts — an article discussing a redirect
        // isn't one. A leading comment doesn't stop it being a redirect, in
        // MediaWiki or here, so those are skipped before anchoring.
        $head = preg_replace('/^(?:\s|<!--.*?-->)*/s', '', $wikitext) ?? $wikitext;

        if (preg_match('/^#REDIRECT\s*\[\[\s*:?\s*([^\]|#]+)/i', $head, $matches) !== 1) {
            return null;
        }

        $target = trim(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $target === '' ? null : $target;
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
    private function version(int $line, ?string $toolingLabel, array $cells): VersionRow
    {
        [$seriesName, $seriesPage, $seriesPosition] = $this->series($cells['series'] ?? '');
        [$baseColor, $baseType] = $this->base($cells['base'] ?? '');

        return new VersionRow(
            line: $line,
            toolingLabel: $toolingLabel,
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
            wheelType: $this->wheel($cells['wheel'] ?? ''),
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

        // "N/A" is the wiki saying there is no number, not number "N" of "A" —
        // which is what splitting on the slash below made of it.
        if ($value === '' || preg_match('#^(n\s*/\s*a|none|-+|—|\?)$#i', $value) === 1) {
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

        if ($this->wiki->compoundSeries) {
            return $this->compoundSeries($parts);
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
     * A series named by more than its first link — the Matchbox wiki's
     * "[[1-75]]: [[Wheeled Envy]]" is the 1-75 range's Wheeled Envy segment, and
     * "5-Pack: [[List of 2010 5-Packs#…|Modern Rides]]" is a 5-pack. The name is
     * the whole line as a reader sees it, and a cell that is nothing but one
     * link still keys by that link, exactly as on the Hot Wheels wiki.
     *
     * The position can trail on the same line ("1-100: Sports Cars 16/17") as
     * well as sit on the next.
     *
     * @param  list<string>  $parts
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    private function compoundSeries(array $parts): array
    {
        $text = Wikitext::plainText($parts[0]);
        $position = null;

        if (preg_match('#^(.*?)\s+(\d+\s*/\s*\d+)$#', $text, $matches) === 1) {
            $text = trim($matches[1]);
            $position = $matches[2];
        }

        if ($position === null && isset($parts[1]) && preg_match('#^\d+\s*/\s*\d+$#', Wikitext::plainText($parts[1])) === 1) {
            $position = Wikitext::plainText($parts[1]);
        }

        $link = Wikitext::firstLink($parts[0]);

        if ($link !== null && $link[1] === $text) {
            return [$link[1], $link[0], $position];
        }

        return [$text === '' ? null : $text, null, $position];
    }

    /**
     * The wheel, as text where the wiki wrote text, and as the wheel image's
     * file name where it wrote a picture — which is how the Matchbox wiki
     * writes most of them. See Wikitext::mediaNames().
     */
    private function wheel(string $cell): ?string
    {
        $text = Wikitext::valueOrNull($cell);

        if ($text !== null) {
            return $text;
        }

        $names = Wikitext::mediaNames($cell);

        return $names === [] ? null : implode(' / ', $names);
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
