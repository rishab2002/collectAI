<?php

namespace App\Services\Import\HotWheelsWiki;

use App\Services\Import\Wikitext\Wikitext;
use App\Services\Import\Wikitext\WikitextTable;

/**
 * Reads a `List of YYYY Hot Wheels` page for the casting articles it names.
 *
 * The index is a work list, not a source of facts. Its own columns repeat what
 * each casting page states in more detail — and states inconsistently, since a
 * car appears once per colour here with the colour only implied by a "(2nd
 * Color)" suffix. Importing from the index would mean reconciling two accounts
 * of the same release; importing the titles and reading each casting page means
 * one account, from the page the facts belong to.
 */
final class YearIndexParser
{
    /**
     * Headers whose cells link to a casting article.
     *
     * Restricted to this column on purpose: the Series column is full of links
     * too, and following those would import "2011 New Models" as a casting.
     */
    private const CASTING_COLUMNS = ['model name', 'model', 'casting', 'casting name', 'car'];

    /**
     * Namespaced or fragment links that are never a casting article.
     */
    private const EXCLUDED = ['file:', 'image:', 'media:', 'category:', 'template:', 'help:', 'user:', 'list of'];

    /**
     * The casting articles this year's index names, de-duplicated and in
     * document order.
     *
     * A casting appears once per colour in the index and once per section it was
     * re-released in; the catalog wants the tool once, so the caller gets each
     * title a single time.
     *
     * @return list<string>
     */
    public function castingTitles(string $wikitext): array
    {
        $titles = [];

        foreach (WikitextTable::allIn($wikitext) as $table) {
            foreach ($table->keyedRows() as $row) {
                foreach ($row as $header => $cell) {
                    if (! in_array($this->normalize($header), self::CASTING_COLUMNS, true)) {
                        continue;
                    }

                    foreach (array_keys(Wikitext::links($cell)) as $target) {
                        $title = $this->title($target);

                        if ($title !== null) {
                            $titles[$title] = true;
                        }
                    }
                }
            }
        }

        return array_keys($titles);
    }

    /**
     * The year the page covers, read from its title.
     */
    public static function yearFromTitle(string $title): ?int
    {
        if (preg_match('/\b(19|20)\d{2}\b/', $title, $matches) !== 1) {
            return null;
        }

        return (int) $matches[0];
    }

    public static function titleForYear(int $year): string
    {
        return "List of {$year} Hot Wheels";
    }

    /**
     * A link target reduced to the article it points at, or null if it isn't one
     * a casting could live at.
     */
    private function title(string $target): ?string
    {
        // A section link still points at the article that holds it.
        $title = trim(explode('#', $target, 2)[0]);

        if ($title === '') {
            return null;
        }

        $lowered = mb_strtolower($title);

        foreach (self::EXCLUDED as $prefix) {
            if (str_starts_with($lowered, $prefix)) {
                return null;
            }
        }

        return $title;
    }

    private function normalize(string $header): string
    {
        return trim(str_replace(['.', '#'], '', mb_strtolower($header)));
    }
}
