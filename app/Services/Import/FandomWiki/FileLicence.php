<?php

namespace App\Services\Import\FandomWiki;

/**
 * Whether a wiki file may be reused, read from the licence templates on its
 * file page — and if so, the licence to credit it under.
 *
 * Deliberately an allow-list. A wiki's community text is CC-BY-SA, but Fandom
 * says plainly that images "may be under different licenses — check each
 * file's page", and on these wikis most are not licensed at all:
 *
 * - `{{Self}}` reads "This file was uploaded by the photographer or author". It
 *   says who took the photo, and grants nobody anything.
 * - `{{Fairuse}}` is the wiki's own claim to show a copyrighted image; it does
 *   not extend to anyone else.
 * - "Used with permission" is permission given to the wiki, not to us.
 * - No template at all is the most common case, and means all rights reserved.
 *
 * Measured on the 2026-10/09 dumps, the allow-listed files are about 2% of the
 * casting articles' images. That is the honest size of what can be used.
 */
final class FileLicence
{
    /**
     * The licence label to credit, or null if the file may not be reused.
     */
    public static function of(string $filePageWikitext): ?string
    {
        preg_match_all('/\{\{\s*([^|}\n]+)/', $filePageWikitext, $matches);

        foreach ($matches[1] as $template) {
            if (($label = self::label(mb_strtolower(trim($template)))) !== null) {
                return $label;
            }
        }

        return null;
    }

    private static function label(string $template): ?string
    {
        if (preg_match('/^cc-by-sa(?:-([\d.]+))?/', $template, $m) === 1) {
            return trim('CC BY-SA '.($m[1] ?? ''));
        }

        if (preg_match('/^cc-by(?:-([\d.]+))?$/', $template, $m) === 1) {
            return trim('CC BY '.($m[1] ?? ''));
        }

        return match (true) {
            in_array($template, ['cc-zero', 'cc0', 'cc-0'], true) => 'CC0',
            str_starts_with($template, 'gfdl') => 'GFDL',
            $template === 'pd', str_starts_with($template, 'pd-'), str_starts_with($template, 'public domain') => 'Public domain',
            // Commons hosts only freely licensed files; the file page there
            // names the exact licence, and the credit links to this page.
            $template === 'from wikimedia' => 'Free licence (Wikimedia Commons)',
            $template === 'other free' => 'Free licence (as stated on the file page)',
            default => null,
        };
    }
}
