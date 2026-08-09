<?php

namespace App\Services\Import;

/**
 * Turning MediaWiki markup into the plain values a catalog row wants.
 *
 * Deliberately small and purely textual: everything here is reversible
 * transcription of what the source states, never interpretation of it. Anything
 * that would guess at meaning — splitting "Metalflake Yellow" into a finish and
 * a colour, resolving a casting to a real make and model — is an inference and
 * belongs in the pipeline behind submission_proposals, not here.
 */
class Wikitext
{
    /**
     * Zero-width space. The Hot Wheels wiki's year indexes are full of them —
     * 138 in the 2011 list alone, mostly trailing the series position ("1/50")
     * — and they survive trim(), so "1/50\u{200b}" != "1/50" and a series
     * position would never match on re-import.
     */
    private const INVISIBLE = "\u{200B}\u{200C}\u{200D}\u{FEFF}\u{00A0}\u{200E}\u{200F}";

    /**
     * A cell's visible text: links resolved to their labels, markup removed,
     * images dropped.
     */
    public static function plain(string $wikitext): string
    {
        $text = self::withoutFiles($wikitext);

        // [[Target|Label]] shows the label; [[Target]] shows the target.
        $text = preg_replace('/\[\[[^\]|]*\|([^\]]*)\]\]/u', '$1', $text) ?? $text;
        $text = preg_replace('/\[\[([^\]]*)\]\]/u', '$1', $text) ?? $text;

        // [https://example.com Label] shows the label.
        $text = preg_replace('/\[(?:https?:)?\/\/\S+\s+([^\]]*)\]/u', '$1', $text) ?? $text;
        $text = preg_replace('/\[(?:https?:)?\/\/\S+\]/u', '', $text) ?? $text;

        // Templates carry no visible text worth keeping in a table cell.
        $text = preg_replace('/\{\{[^{}]*\}\}/u', '', $text) ?? $text;

        // <br> is a value separator in these tables, not whitespace — the caller
        // splits on it before reaching here, so anything left is a line break.
        $text = preg_replace('/<br\s*\/?>/iu', ' ', $text) ?? $text;
        $text = preg_replace('/<[^>]+>/u', '', $text) ?? $text;

        $text = str_replace(["'''", "''"], '', $text);

        // Named entities the wiki uses in year ranges and titles.
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return self::tidy($text);
    }

    /**
     * The page a cell links to, which is the stable identity behind a label.
     *
     * "[['07 Shelby GT-500|'07 Ford Shelby GT-500]]" displays one name and lives
     * at another; the target is what a second row — or a second year's index —
     * will agree with, so casting and series identity resolve through this
     * rather than through the label.
     */
    public static function linkTarget(string $wikitext): ?string
    {
        if (preg_match('/\[\[([^\]|#]+)/u', self::withoutFiles($wikitext), $matches) !== 1) {
            return null;
        }

        $target = self::tidy(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $target === '' ? null : $target;
    }

    /**
     * Splits a cell on <br>, which these tables use to stack two distinct values
     * in one cell — "[[2011 New Models]]<br>32/50" is a series and its position,
     * and "Chrome<br>/<br>Plastic" is a base colour and its material.
     *
     * @return list<string>
     */
    public static function lines(string $wikitext): array
    {
        $parts = preg_split('/<br\s*\/?>/iu', $wikitext) ?: [];

        return array_values(array_filter(
            array_map(self::tidy(...), $parts),
            fn (string $part): bool => $part !== '' && $part !== '/',
        ));
    }

    /**
     * Collapses whitespace and strips the invisible characters that would
     * otherwise make two identical-looking values compare unequal.
     */
    public static function tidy(string $text): string
    {
        $text = str_replace(mb_str_split(self::INVISIBLE), '', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Null rather than an empty string, so an absent value stays absent through
     * to a nullable column instead of becoming ''. The variations index
     * coalesces NULL to '' for comparison, but storing '' would misreport "the
     * source didn't say" as "the source said nothing is there".
     */
    public static function nullIfBlank(?string $text): ?string
    {
        $text = self::tidy((string) $text);

        return $text === '' ? null : $text;
    }

    /**
     * Image and file syntax, which nests and so cannot be removed by the plain
     * link patterns. Dropped entirely: the import takes facts, and the wiki's
     * 165,987 images are neither facts nor ours to copy.
     */
    private static function withoutFiles(string $wikitext): string
    {
        $previous = null;
        $text = $wikitext;

        // Repeated because [[File:x|[[y]]]] only reduces once the inner link goes.
        while ($previous !== $text) {
            $previous = $text;
            $text = preg_replace('/\[\[\s*(?:File|Image)\s*:[^\[\]]*\]\]/iu', '', $text) ?? $text;
        }

        return $text;
    }
}
