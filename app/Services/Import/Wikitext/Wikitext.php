<?php

namespace App\Services\Import\Wikitext;

/**
 * The small amount of wikitext understanding the importer needs to turn a table
 * cell into a value.
 *
 * Deliberately not a general wikitext renderer. Everything here exists because a
 * real cell in a Versions table needed it: markup that would otherwise end up
 * inside a colour name, links that carry the identity of a series, and the
 * `<br>` that separates two facts crammed into one cell.
 */
final class Wikitext
{
    /**
     * Zero-width characters. The year index pastes them after series positions
     * ("1/50\u{200B}"), where they survive trim() and turn an == comparison into
     * a puzzle.
     */
    private const INVISIBLE = "\u{200B}\u{200C}\u{200D}\u{FEFF}\u{00A0}";

    /**
     * Cell markup reduced to the text a human reads: links resolved to their
     * display text, bold and italics dropped, entities decoded.
     *
     * Images go entirely rather than becoming their caption — a `[[File:x|75px]]`
     * left half-parsed puts "75px" in a colour column, and the images are not
     * something this importer takes anyway.
     */
    public static function plainText(string $wikitext): string
    {
        $text = self::withoutMedia($wikitext);

        // Comments before anything else: one can wrap markup that would
        // otherwise be parsed as real.
        $text = preg_replace('/<!--.*?-->/s', '', $text) ?? $text;
        $text = preg_replace('/<ref[^>]*>.*?<\/ref>|<ref[^>]*\/>/si', '', $text) ?? $text;

        // [[Target|Display]] keeps the display text, [[Target]] the target. This
        // is what a reader sees, which is what belongs in a name column.
        $text = preg_replace('/\[\[[^\]|]*\|([^\]]*)\]\]/', '$1', $text) ?? $text;
        $text = preg_replace('/\[\[([^\]]*)\]\]/', '$1', $text) ?? $text;

        // [https://example.com Label] -> Label, and a bare external link goes.
        $text = preg_replace('/\[(?:https?:)?\/\/\S+\s+([^\]]*)\]/', '$1', $text) ?? $text;
        $text = preg_replace('/\[(?:https?:)?\/\/\S+\]/', '', $text) ?? $text;

        $text = str_replace(['<nowiki/>', '<nowiki />'], '', $text);
        $text = preg_replace("/'''''|'''|''/", '', $text) ?? $text;
        $text = preg_replace('/<[^>]+>/', ' ', $text) ?? $text;

        return self::normalizeWhitespace(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * The two halves of a cell that holds two facts, split on the line break the
     * wiki uses to stack them — "[[2011 New Models]]<br>2/50".
     *
     * @return list<string>
     */
    public static function splitOnBreaks(string $wikitext): array
    {
        $parts = preg_split('/<br\s*\/?>/i', $wikitext) ?: [$wikitext];

        return array_values(array_filter(
            array_map(self::normalizeWhitespace(...), $parts),
            static fn (string $part): bool => $part !== '',
        ));
    }

    /**
     * The first wiki link in a cell, as [target, display].
     *
     * The target is what makes a series identity resolvable rather than a bare
     * string: "Muscle Mania: Ford" is ambiguous across years, and
     * "Muscle Mania - Ford Series (2012)" is not. Both are kept because the
     * display text is the name a collector would recognise.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function firstLink(string $wikitext): ?array
    {
        if (preg_match('/\[\[([^\]|]+)(?:\|([^\]]*))?\]\]/', self::withoutMedia($wikitext), $matches) !== 1) {
            return null;
        }

        $target = self::normalizeWhitespace(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $display = self::plainText($matches[2] ?? '');

        return [$target, $display !== '' ? $display : $target];
    }

    /**
     * Every wiki link in a cell, as target => display, in document order and
     * de-duplicated. What a year index's Model Name column is read with.
     *
     * @return array<string, string>
     */
    public static function links(string $wikitext): array
    {
        preg_match_all('/\[\[([^\]|]+)(?:\|([^\]]*))?\]\]/', self::withoutMedia($wikitext), $matches, PREG_SET_ORDER);

        $links = [];

        foreach ($matches as $match) {
            $target = self::normalizeWhitespace(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ($target === '' || isset($links[$target])) {
                continue;
            }

            $display = self::plainText($match[2] ?? '');
            $links[$target] = $display !== '' ? $display : $target;
        }

        return $links;
    }

    /**
     * The file names a cell's images point at, without the extension, in order.
     *
     * Only for a cell where the image *is* the value. The Matchbox wiki writes
     * most wheel types as a picture of the wheel — `[[File:M002f.jpg|65px]]` —
     * and the file name is the identifier its editors use for that wheel.
     * Dropping it would make two cars that differ only in wheels identical.
     * The image itself is never fetched.
     *
     * @return list<string>
     */
    public static function mediaNames(string $wikitext): array
    {
        preg_match_all('/\[\[\s*(?:File|Image)\s*:\s*([^|\]]+)/i', $wikitext, $matches);

        $names = [];

        foreach ($matches[1] as $file) {
            $name = self::normalizeWhitespace(html_entity_decode(
                preg_replace('/\.(?:jpe?g|png|gif|webp|svg)$/i', '', trim($file)) ?? $file,
                ENT_QUOTES | ENT_HTML5,
                'UTF-8',
            ));

            if ($name !== '' && ! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Null rather than an empty string, so "the wiki didn't say" and "the wiki
     * said nothing useful" arrive at the database as the same thing — a NULL the
     * variation uniqueness index already knows how to compare.
     */
    public static function valueOrNull(string $wikitext): ?string
    {
        $text = self::plainText($wikitext);

        return $text === '' ? null : $text;
    }

    /**
     * Strips file and image links whole, including the ones nested one level
     * deep inside a caption. Done as a bounded loop rather than a recursive
     * pattern because the nesting here is shallow and a runaway regex on 11,000
     * pages is a worse failure than an unstripped caption.
     */
    private static function withoutMedia(string $wikitext): string
    {
        $pattern = '/\[\[\s*(?:File|Image|Media)\s*:(?:[^\[\]]|\[\[[^\]]*\]\])*\]\]/i';

        for ($pass = 0; $pass < 3; $pass++) {
            $stripped = preg_replace($pattern, '', $wikitext) ?? $wikitext;

            if ($stripped === $wikitext) {
                break;
            }

            $wikitext = $stripped;
        }

        return $wikitext;
    }

    private static function normalizeWhitespace(string $text): string
    {
        $text = preg_replace('/['.self::INVISIBLE.']/u', '', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
