<?php

namespace App\Services\Import\Wikitext;

/**
 * A `{| ... |}` wikitable, flattened into a grid.
 *
 * The reason this exists rather than a `explode("|-")` in the importer is
 * `rowspan`. A Versions table routinely states a colour once and spans it down
 * two rows, so the row underneath carries five cells where the header declares
 * thirteen. Splitting on the row separator and zipping against the headers puts
 * a toy number in the colour column and a note in the wheel column — silently,
 * and for exactly the rows that describe the interesting variants.
 *
 * Cells are returned as raw wikitext. Turning them into values is the caller's
 * job, because what a cell means differs per column.
 */
final class WikitextTable
{
    /**
     * @param  list<string>  $headers  Raw header cells, in column order.
     * @param  list<list<string>>  $rows  Raw body cells, padded to the header count.
     */
    private function __construct(
        public readonly array $headers,
        public readonly array $rows,
    ) {}

    /**
     * The first table in a page, or null if it has none.
     */
    public static function firstIn(string $wikitext): ?self
    {
        return self::allIn($wikitext)[0] ?? null;
    }

    /**
     * Every top-level table in a page, in document order. Nested tables stay
     * part of their parent's cell rather than being lifted out — a table inside
     * a cell is that cell's content, not a sibling.
     *
     * @return list<self>
     */
    public static function allIn(string $wikitext): array
    {
        $tables = [];
        $offset = 0;

        while (($start = strpos($wikitext, '{|', $offset)) !== false) {
            $depth = 0;
            $cursor = $start;
            $end = null;

            // Walk the opening and closing markers rather than regex-matching to
            // the first `|}`, which would end a table at its first nested one.
            while ($cursor < strlen($wikitext)) {
                $open = strpos($wikitext, '{|', $cursor);
                $close = strpos($wikitext, '|}', $cursor);

                if ($close === false) {
                    break;
                }

                if ($open !== false && $open < $close) {
                    $depth++;
                    $cursor = $open + 2;

                    continue;
                }

                $depth--;
                $cursor = $close + 2;

                if ($depth === 0) {
                    $end = $close;

                    break;
                }
            }

            if ($end === null) {
                break;
            }

            $tables[] = self::parse(substr($wikitext, $start + 2, $end - $start - 2));
            $offset = $end + 2;
        }

        return $tables;
    }

    /**
     * The body rows keyed by their header cell, which is how a caller should
     * read them: these tables vary their column set and their column order
     * between pages, so anything indexed by position is one edit away from being
     * wrong.
     *
     * Headers are normalised (markup stripped, lowercased) so "'''Col #'''" and
     * "Col #" are the same key. A duplicated header keeps the first column.
     *
     * @return list<array<string, string>>
     */
    public function keyedRows(): array
    {
        $keys = array_map(
            static fn (string $header): string => mb_strtolower(Wikitext::plainText($header)),
            $this->headers,
        );

        return array_map(static function (array $cells) use ($keys): array {
            $row = [];

            foreach ($keys as $index => $key) {
                if ($key !== '' && ! array_key_exists($key, $row)) {
                    $row[$key] = $cells[$index] ?? '';
                }
            }

            return $row;
        }, $this->rows);
    }

    /**
     * Splits the table body into rows and cells, then resolves the spans.
     */
    private static function parse(string $body): self
    {
        $headers = [];
        $rows = [];
        $current = null;

        // Whether a data cell has appeared yet, which is what separates the
        // header row from the body — not position. Plenty of these tables open
        // with a `|-` before their headers, and treating "before the first row
        // separator" as the header row reads every header as data and leaves the
        // table with no column names at all.
        $seenData = false;

        // The remainder of the `{|` line is the table's own attributes.
        $lines = explode("\n", $body);
        array_shift($lines);

        foreach ($lines as $line) {
            $trimmed = ltrim($line);

            // A caption, often an empty `|+` the visual editor leaves behind. It
            // carries no row data — and read as a data cell, it marks the body
            // as started before the header row, so every header after it is
            // taken for data and the table loses its column names (Baja Bug).
            if (str_starts_with($trimmed, '|+')) {
                continue;
            }

            if (str_starts_with($trimmed, '|-')) {
                if ($current !== null && $current !== []) {
                    $rows[] = $current;
                }

                $current = [];

                continue;
            }

            if (str_starts_with($trimmed, '!')) {
                $cells = self::splitInline(substr($trimmed, 1), '!!');

                // Once the body has started, a `!` is a row-level header cell —
                // these tables use one for the occasional label column — and
                // belongs to its row rather than to the column names.
                if ($seenData) {
                    $current = [...($current ?? []), ...$cells];
                } else {
                    $headers = [...$headers, ...$cells];
                }

                continue;
            }

            if (str_starts_with($trimmed, '|')) {
                $cells = self::splitInline(substr($trimmed, 1), '||');
                $seenData = true;

                if ($current === null) {
                    // A table whose first row omits the leading `|-`.
                    $current = [];
                }

                $current = [...$current, ...$cells];

                continue;
            }

            // A continuation line: multi-line cell content, which templates and
            // stacked notes both produce. Appended to the cell it belongs to
            // rather than dropped, or a `<br>`-separated value loses its tail.
            if ($trimmed === '') {
                continue;
            }

            $target = &$current;

            if ($current === null || $current === []) {
                $target = &$headers;
            }

            if ($target !== []) {
                $target[array_key_last($target)] .= "\n".rtrim($line);
            }

            unset($target);
        }

        if ($current !== null && $current !== []) {
            $rows[] = $current;
        }

        return new self(
            array_map(self::content(...), $headers),
            self::resolveSpans($rows, count($headers)),
        );
    }

    /**
     * Expands rowspan and colspan into a rectangular grid.
     *
     * Carried cells are held per column with a remaining count, and consumed
     * before the row's own cells are placed — which is what makes a row that
     * only states the columns it changes line up with the header again.
     *
     * @param  list<list<string>>  $rows  Raw cells, still carrying their attributes.
     * @return list<list<string>>
     */
    private static function resolveSpans(array $rows, int $width): array
    {
        /** @var array<int, array{value: string, remaining: int}> $carried */
        $carried = [];
        $resolved = [];

        foreach ($rows as $cells) {
            $row = [];
            $column = 0;
            $next = 0;

            // A row can be wider than the header when a page adds a column
            // without updating it, so the grid is at least as wide as the row
            // could reach rather than truncated to the declared width.
            while ($column < $width || $next < count($cells)) {
                if (isset($carried[$column]) && $carried[$column]['remaining'] > 0) {
                    $row[$column] = $carried[$column]['value'];
                    $carried[$column]['remaining']--;
                    $column++;

                    continue;
                }

                if ($next >= count($cells)) {
                    break;
                }

                $cell = $cells[$next++];
                $content = self::content($cell);
                $rowspan = self::span($cell, 'rowspan');

                for ($span = 0; $span < self::span($cell, 'colspan'); $span++) {
                    $row[$column] = $content;

                    if ($rowspan > 1) {
                        $carried[$column] = ['value' => $content, 'remaining' => $rowspan - 1];
                    }

                    $column++;
                }
            }

            ksort($row);
            $resolved[] = array_values(array_replace(array_fill(0, max($width, count($row)), ''), $row));
        }

        return $resolved;
    }

    /**
     * Splits a line into cells on the inline separator, ignoring separators that
     * belong to a link or a template.
     *
     * @return list<string>
     */
    private static function splitInline(string $line, string $separator): array
    {
        $cells = [];
        $buffer = '';
        $depth = 0;
        $length = strlen($line);

        for ($index = 0; $index < $length; $index++) {
            $pair = substr($line, $index, 2);

            if ($pair === '[[' || $pair === '{{' || $pair === '{|') {
                $depth++;
                $buffer .= $pair;
                $index++;

                continue;
            }

            if ($pair === ']]' || $pair === '}}' || $pair === '|}') {
                $depth = max(0, $depth - 1);
                $buffer .= $pair;
                $index++;

                continue;
            }

            if ($depth === 0 && $pair === $separator) {
                $cells[] = $buffer;
                $buffer = '';
                $index++;

                continue;
            }

            $buffer .= $line[$index];
        }

        $cells[] = $buffer;

        return $cells;
    }

    /**
     * A cell's content with any HTML attributes removed.
     *
     * The attribute half is only recognised when it actually looks like
     * attributes. MediaWiki has the same ambiguity — `|Red | Blue` is one cell,
     * `| rowspan="2" |Red` is an attribute and a value — and guessing wrong the
     * other way silently truncates a colour name at its first pipe.
     */
    private static function content(string $cell): string
    {
        [$attributes, $content] = self::split($cell);

        return trim($attributes === null ? $content : $content);
    }

    private static function span(string $cell, string $attribute): int
    {
        [$attributes] = self::split($cell);

        if ($attributes === null || preg_match('/\b'.$attribute.'\s*=\s*"?(\d+)"?/i', $attributes, $matches) !== 1) {
            return 1;
        }

        return max(1, (int) $matches[1]);
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private static function split(string $cell): array
    {
        // Only the first depth-0 pipe can separate attributes from content.
        $pipe = null;
        $depth = 0;
        $length = strlen($cell);

        for ($index = 0; $index < $length; $index++) {
            $pair = substr($cell, $index, 2);

            if ($pair === '[[' || $pair === '{{' || $pair === '{|') {
                $depth++;
                $index++;

                continue;
            }

            if ($pair === ']]' || $pair === '}}' || $pair === '|}') {
                $depth = max(0, $depth - 1);
                $index++;

                continue;
            }

            if ($depth === 0 && $cell[$index] === '|') {
                $pipe = $index;

                break;
            }
        }

        if ($pipe === null) {
            return [null, $cell];
        }

        $attributes = substr($cell, 0, $pipe);

        // Blank, or an `name=` pair. Anything else is content that happened to
        // contain a pipe.
        if (trim($attributes) !== '' && preg_match('/^\s*[a-zA-Z][\w-]*\s*=/', $attributes) !== 1) {
            return [null, $cell];
        }

        return [$attributes, substr($cell, $pipe + 1)];
    }
}
