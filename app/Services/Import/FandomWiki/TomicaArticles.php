<?php

namespace App\Services\Import\FandomWiki;

use App\Services\Import\ImportSummary;

/**
 * Turns Tomica wiki articles into castings the CatalogImporter can write.
 *
 * The Tomica wiki has one article per *release item* ("LV-177b Nissan Skyline
 * 2000 GT-R (70)") rather than one per tool with a Versions table, and states
 * its structured facts in the title and the categories — toy line ("Tomica
 * Limited Vintage"), year ("2019 Tomica"). The article body is prose, which is
 * never read (CC-BY-SA text is the editors'; only facts are taken).
 *
 * Grouping into castings:
 * - **TLV and TLV Neo** number a tool and letter its variants: LV-01a, LV-01b
 *   and LV-01c are one Nissan Cedric in three finishes. The base code (LV-01)
 *   is the casting; each lettered article is a variation.
 * - **Classic Tomica, Premium, Limited** reuse a number for different cars over
 *   the years (No. 20 has been eight vehicles), so the number cannot identify a
 *   tool; the car's name does.
 *
 * Every key is built from those facts, never from the order articles arrive
 * in, so a re-import updates rather than duplicates.
 */
final class TomicaArticles
{
    /**
     * The toy lines imported, in priority order (an article in two of these
     * categories goes to the first), mapped to the line name the catalog shows.
     */
    public const LINES = [
        'Tomica Limited Vintage Neo' => 'Tomica Limited Vintage Neo',
        'Tomica Limited Vintage' => 'Tomica Limited Vintage',
        'Tomica Premium' => 'Tomica Premium',
        'Tomica Limited' => 'Tomica Limited',
        'Classic Tomica' => 'Classic Tomica',
    ];

    /**
     * Item codes, longest form first. Group 1 is the code; group 2 the name.
     */
    private const CODES = [
        '/^(LV-N\s?\d+[a-z]?)\s+(.+)$/u',
        '/^(LV-[A-Za-z]+\s?\d+[a-z]?)\s+(.+)$/u',
        '/^(LV-\d+[a-z]?)\s+(.+)$/u',
        '/^((?:Premium\s+)?No\.\s?\d+)\s+(.+)$/u',
    ];

    public function __construct(private readonly Wiki $wiki) {}

    /**
     * @param  iterable<WikiPage>  $pages  Articles from the line categories, each with its categories.
     * @return list<CastingPage>
     */
    public function castings(iterable $pages, ImportSummary $summary): array
    {
        $groups = [];

        foreach ($pages as $page) {
            if ($page->redirectsTo() !== null) {
                continue;
            }

            $line = $this->line($page->categories);

            if ($line === null) {
                continue;
            }

            $year = $this->year($page->categories);

            if ($year === null) {
                $summary->problem("{$page->title}: no year category");

                continue;
            }

            [$code, $name] = $this->split($page->title);

            // TLV codes letter their variants; the base code is the tool.
            $base = $code !== null && str_starts_with($code, 'LV') ? preg_replace('/(\d)[a-z]$/u', '$1', $code) : null;
            $group = $line.'|'.($base ?? $name);

            $groups[$group][] = ['page' => $page, 'code' => $code, 'name' => $name, 'year' => $year, 'line' => $line, 'base' => $base];
        }

        $castings = [];

        foreach ($groups as $group => $members) {
            // The earliest article (lowest page id) stands for the casting;
            // the shortest name is the tool's own, without a variant's livery.
            usort($members, fn (array $a, array $b): int => $a['page']->pageId <=> $b['page']->pageId);
            $names = array_column($members, 'name');
            usort($names, fn (string $a, string $b): int => mb_strlen($a) <=> mb_strlen($b));

            $versions = array_map(fn (array $member): VersionRow => new VersionRow(
                // The article's own page id: stable however many variants
                // are added later, which a position in the group would not be.
                line: $member['page']->pageId,
                toolingLabel: null,
                year: $member['year'],
                collectorNumber: $member['code'],
                seriesName: $member['line'],
                seriesPage: null,
                seriesPosition: null,
                color: null,
                tampo: null,
                baseColor: null,
                baseType: null,
                windowColor: null,
                interiorColor: null,
                wheelType: null,
                // The item code is what is printed on the box, and the one
                // fact that tells LV-01a from LV-01b.
                toyNumber: $member['code'],
                country: null,
            ), $members);

            $castings[] = new CastingPage(
                title: $members[0]['page']->title,
                pageId: $members[0]['page']->pageId,
                revisionId: $members[0]['page']->revisionId,
                name: $names[0],
                designer: null,
                versions: $versions,
                wiki: $this->wiki,
                key: 'casting:'.str_replace(' ', '_', $group),
            );
        }

        return $castings;
    }

    /**
     * @param  list<string>  $categories
     */
    private function line(array $categories): ?string
    {
        foreach (self::LINES as $category => $line) {
            if (in_array($category, $categories, true)) {
                return $line;
            }
        }

        return null;
    }

    /**
     * The earliest "YYYY Tomica" category — an article re-released later is
     * filed under both years, and the first is when the item came out.
     *
     * @param  list<string>  $categories
     */
    private function year(array $categories): ?int
    {
        $years = [];

        foreach ($categories as $category) {
            if (preg_match('/^((?:19|20)\d{2}) Tomica$/', $category, $matches) === 1) {
                $years[] = (int) $matches[1];
            }
        }

        return $years === [] ? null : min($years);
    }

    /**
     * @return array{0: string|null, 1: string} the item code (or null) and the name
     */
    private function split(string $title): array
    {
        foreach (self::CODES as $pattern) {
            if (preg_match($pattern, $title, $matches) === 1) {
                return [preg_replace('/\s+/', ' ', $matches[1]), trim($matches[2])];
            }
        }

        return [null, $title];
    }
}
