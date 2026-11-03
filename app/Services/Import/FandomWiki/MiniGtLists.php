<?php

namespace App\Services\Import\FandomWiki;

use App\Services\Import\ImportSummary;
use App\Services\Import\Wikitext\Wikitext;
use App\Services\Import\Wikitext\WikitextTable;

/**
 * Reads the Mini GT wiki's yearly lists ("2021 Models") into castings.
 *
 * The lists are the wiki's structured part: item code, name, steering side,
 * maker, photo. The per-item articles hold only photos and a category, so the
 * lists are where the facts are.
 *
 * **One casting per item, knowingly.** Mini GT names an item by car *and*
 * livery ("BMW M3 (E30) ALPINA B6 3.5S Alpina Blue") and the wiki records
 * nothing that says which items share a tool. Splitting the livery off by
 * guesswork would merge different tools that happen to share a nameplate —
 * the error one-casting-per-tool exists to prevent — so each item stands alone
 * until someone with the cars in hand groups them.
 *
 * Coverage is the wiki's: lists exist for 2018–2022 only (measured 2026-11-03).
 */
final class MiniGtLists
{
    public function __construct(private readonly Wiki $wiki) {}

    /**
     * Titles of the yearly lists the wiki has, or might have.
     *
     * @return list<string>
     */
    public static function listTitles(int $from = 2016, int $to = 2030): array
    {
        return array_map(fn (int $year): string => "{$year} Models", range($from, $to));
    }

    /**
     * @return list<CastingPage>
     */
    public function castings(WikiPage $list, ImportSummary $summary): array
    {
        if (preg_match('/^((?:19|20)\d{2}) Models$/', $list->title, $matches) !== 1) {
            return [];
        }

        $year = (int) $matches[1];
        $castings = [];

        foreach (WikitextTable::allIn($list->wikitext) as $table) {
            foreach ($table->keyedRows() as $row) {
                $code = Wikitext::valueOrNull($row['code'] ?? '');

                if ($code === null || preg_match('/^[A-Z]{2,5}\d{2,6}$/', $code) !== 1) {
                    continue;
                }

                $release = mb_strtolower(Wikitext::plainText($row['release'] ?? $row['year'] ?? ''));

                if (str_contains($release, 'cancel')) {
                    $summary->record('items.cancelled');

                    continue;
                }

                $link = Wikitext::firstLink($row['name'] ?? '');
                $name = $link[1] ?? Wikitext::valueOrNull($row['name'] ?? '');

                if ($name === null) {
                    $summary->problem("{$list->title}: {$code} has no name");

                    continue;
                }

                $castings[] = new CastingPage(
                    // The item's own article, so the attribution link lands on
                    // the car; the list where there is none.
                    title: $link[0] ?? $list->title,
                    pageId: $list->pageId,
                    revisionId: $list->revisionId,
                    name: $name,
                    designer: null,
                    versions: [new VersionRow(
                        line: 1,
                        toolingLabel: null,
                        year: $year,
                        collectorNumber: $code,
                        seriesName: $this->series($code),
                        seriesPage: null,
                        seriesPosition: null,
                        color: null,
                        tampo: null,
                        baseColor: null,
                        baseType: null,
                        windowColor: null,
                        interiorColor: null,
                        wheelType: null,
                        toyNumber: $code,
                        country: null,
                    )],
                    wiki: $this->wiki,
                    // The item number is the identity: it is printed on the
                    // box and survives the list being reordered or re-titled.
                    key: "item:{$code}",
                );
            }
        }

        return $castings;
    }

    /**
     * Kaido House items are their own line; everything else is Mini GT.
     */
    private function series(string $code): string
    {
        return str_starts_with($code, 'KHMG') ? 'Kaido House' : 'Mini GT';
    }
}
