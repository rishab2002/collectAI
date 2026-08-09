<?php

namespace App\Services\Import;

/**
 * Turns a MediaWiki table into rows keyed by their column headings.
 *
 * The whole reason this exists rather than a split on "|" is rowspan. The Hot
 * Wheels casting tables lean on it constantly: a release sold with four wheel
 * types is written as one row carrying rowspan="4" on every shared cell,
 * followed by three rows holding only the cells that differ. Row two of that
 * block has three cells where the header has thirteen, so a parser that zips
 * cells against headings positionally doesn't merely lose data — it reads a
 * wheel type as a collector number and imports it.
 *
 * Carrying spanned cells down is also what makes the grouping legible: a
 * rowspan block is a release, and each row inside it is one variation of it.
 */
class WikitableParser
{
    /**
     * Every table on the page, in order, each as a list of heading-keyed rows.
     *
     * @return list<list<array<string, string>>>
     */
    public static function parseAll(string $wikitext): array
    {
        return array_map(self::parseTable(...), self::tableBodies($wikitext));
    }

    /**
     * The first table, or an empty list if the wikitext holds none.
     *
     * @return list<array<string, string>>
     */
    public static function parseFirst(string $wikitext): array
    {
        $bodies = self::tableBodies($wikitext);

        return $bodies === [] ? [] : self::parseTable($bodies[0]);
    }

    /**
     * @return list<array<string, string>>
     */
    private static function parseTable(string $body): array
    {
        $blocks = preg_split('/^\s*\|-.*$/m', $body) ?: [];

        if ($blocks === []) {
            return [];
        }

        // The header is the first block that actually holds header cells, not
        // simply the first block. Pages disagree about whether a row separator
        // precedes the header — the Nissan 350Z article writes "{|" straight
        // into "!Col #", while the '07 Shelby GT-500 puts a "|-" between them —
        // and assuming the latter's layout was the former's silently reduced
        // every such page to zero rows. It failed quietly, too: a page that
        // parses to nothing looks exactly like a casting with no releases that
        // year.
        $headings = [];

        while ($blocks !== []) {
            $headings = self::headings(array_shift($blocks));

            if ($headings !== []) {
                break;
            }
        }

        if ($headings === []) {
            return [];
        }

        $rows = [];

        /** @var array<int, array{value: string, remaining: int}> $spans */
        $spans = [];

        foreach ($blocks as $block) {
            $cells = self::cells($block);

            if ($cells === [] && $spans === []) {
                continue;
            }

            $row = [];
            $next = 0;

            foreach ($headings as $column => $heading) {
                // A cell still spanning from an earlier row occupies this column,
                // and the cells present in this block belong to the columns it
                // doesn't cover.
                if (isset($spans[$column]) && $spans[$column]['remaining'] > 0) {
                    $row[$heading] = $spans[$column]['value'];
                    $spans[$column]['remaining']--;

                    continue;
                }

                if (! isset($cells[$next])) {
                    continue;
                }

                [$value, $span] = self::stripAttributes($cells[$next]);
                $next++;

                if ($span > 1) {
                    $spans[$column] = ['value' => $value, 'remaining' => $span - 1];
                }

                $row[$heading] = $value;
            }

            if ($row !== []) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * The header cells, cleaned to the bare column name so callers can key on
     * "Toy #" without reproducing the styling the wiki wraps it in.
     *
     * @return array<int, string>
     */
    private static function headings(string $block): array
    {
        $headings = [];

        foreach (preg_split('/\r?\n/', $block) ?: [] as $line) {
            $line = trim($line);

            if (! str_starts_with($line, '!')) {
                continue;
            }

            // "!! " separates headers written on one line.
            foreach (explode('!!', ltrim($line, '!')) as $cell) {
                [$value] = self::stripAttributes($cell);

                // "Base Color<br>/<br>Type" is one column, and the <br> is
                // presentational here rather than a value separator.
                $heading = Wikitext::tidy(Wikitext::plain($value));

                if ($heading !== '') {
                    $headings[] = $heading;
                }
            }
        }

        return $headings;
    }

    /**
     * @return list<string>
     */
    private static function cells(string $block): array
    {
        $cells = [];

        foreach (preg_split('/\r?\n/', $block) ?: [] as $line) {
            if (! str_starts_with(ltrim($line), '|')) {
                continue;
            }

            $line = ltrim($line);

            if (str_starts_with($line, '|}') || str_starts_with($line, '|-') || str_starts_with($line, '|+')) {
                continue;
            }

            // "|| " separates cells written on one line. Splitting on it is safe
            // because a wiki link uses single brackets and a template uses
            // braces — neither can contain a bare "||".
            foreach (explode('||', substr($line, 1)) as $cell) {
                $cells[] = $cell;
            }
        }

        return $cells;
    }

    /**
     * Separates a cell's HTML attributes from its content, returning the content
     * and how many rows it spans.
     *
     * The separator is a single "|", but a cell's *content* frequently contains
     * one — "[[List of 2006 Hot Wheels|2006]]" — so the split has to be on the
     * first pipe that isn't inside link or template brackets, and only when what
     * precedes it actually looks like an attribute list.
     *
     * @return array{0: string, 1: int}
     */
    private static function stripAttributes(string $cell): array
    {
        $depth = 0;
        $length = strlen($cell);

        for ($i = 0; $i < $length; $i++) {
            $character = $cell[$i];

            if ($character === '[' || $character === '{') {
                $depth++;

                continue;
            }

            if ($character === ']' || $character === '}') {
                $depth--;

                continue;
            }

            if ($character !== '|' || $depth > 0) {
                continue;
            }

            $attributes = substr($cell, 0, $i);

            // "attr=value" is a marker no cell content has; anything else is
            // content that happened to contain a pipe, so the cell has no
            // attribute section at all.
            if (! str_contains($attributes, '=')) {
                break;
            }

            $span = 1;

            if (preg_match('/rowspan\s*=\s*"?(\d+)"?/i', $attributes, $matches) === 1) {
                $span = max(1, (int) $matches[1]);
            }

            return [trim(substr($cell, $i + 1)), $span];
        }

        return [trim($cell), 1];
    }

    /**
     * @return list<string>
     */
    private static function tableBodies(string $wikitext): array
    {
        $bodies = [];
        $depth = 0;
        $start = 0;
        $lines = preg_split('/\r?\n/', $wikitext) ?: [];

        foreach ($lines as $index => $line) {
            $trimmed = ltrim($line);

            if (str_starts_with($trimmed, '{|')) {
                if ($depth === 0) {
                    $start = $index;
                }

                $depth++;

                continue;
            }

            if ($depth > 0 && str_starts_with($trimmed, '|}')) {
                $depth--;

                if ($depth === 0) {
                    $bodies[] = implode("\n", array_slice($lines, $start, $index - $start));
                }
            }
        }

        return $bodies;
    }
}
