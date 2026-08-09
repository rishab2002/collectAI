<?php

namespace App\Services\Import\HotWheelsWiki;

use App\Services\Import\WikitableParser;
use App\Services\Import\Wikitext;

/**
 * Reads "List of YYYY Hot Wheels" for the casting pages that year touched.
 *
 * The index is used as a worklist, not as a source of records. Its tables carry
 * only Toy #, Col #, model name, series and a photo — no colour, tampo, wheel
 * or base data — so it cannot produce a variation, and importing releases from
 * it would create rows the casting pages then duplicate with fuller detail. The
 * casting page each row links to is the authority; this just says which ones to
 * open.
 */
class YearIndexParser
{
    /**
     * Distinct casting page titles the year's tables link to, in first-seen
     * order.
     *
     * Deduplicated because a casting appears once per colour — the 2011 index
     * lists the '07 Shelby GT-500 three times, as the release and its "(2nd
     * Color)" and "(3rd Color)" siblings — and all three are the same article.
     *
     * @return list<string>
     */
    public function castingTitles(string $wikitext): array
    {
        $titles = [];

        foreach ($this->rows($wikitext) as $row) {
            $title = $this->castingTitle($row);

            if ($title !== null) {
                $titles[$title] = true;
            }
        }

        return array_keys($titles);
    }

    /**
     * The series named in the year's section headings, which is the closest the
     * wiki gets to a canonical list of that year's series.
     *
     * @return list<string>
     */
    public function seriesNames(string $wikitext): array
    {
        $names = [];

        if (preg_match_all('/^={2,}\s*(.+?)\s*={2,}\s*$/mu', $wikitext, $matches) === false) {
            return [];
        }

        foreach ($matches[1] as $heading) {
            $name = Wikitext::nullIfBlank(Wikitext::plain($heading));

            if ($name !== null) {
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * The link target of the model-name cell.
     *
     * Taken from the target rather than the label because the label is written
     * for the page it sits on — "[['07 Shelby GT-500|'07 Ford Shelby GT-500]]"
     * renders a fuller name than the article it points at — and the target is
     * what a second year's index will agree with. The "(2nd Color)" suffix sits
     * outside the link, so it disappears with the label.
     */
    private function castingTitle(array $row): ?string
    {
        foreach (['Model Name', 'Model', 'Casting', 'Name'] as $heading) {
            foreach ($row as $key => $value) {
                if (strcasecmp(trim($key), $heading) !== 0) {
                    continue;
                }

                $target = Wikitext::linkTarget($value);

                if ($target !== null) {
                    return $target;
                }
            }
        }

        return null;
    }

    /**
     * @return list<array<string, string>>
     */
    private function rows(string $wikitext): array
    {
        $rows = [];

        foreach (WikitableParser::parseAll($wikitext) as $table) {
            foreach ($table as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
