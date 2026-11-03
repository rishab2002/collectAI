<?php

namespace App\Services\Import\FandomWiki;

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
    private const CASTING_COLUMNS = ['model name', 'model', 'casting', 'casting name', 'car', 'car name', 'name'];

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

        // Three shapes of index, from three eras of the wiki, and one page can
        // mix them. Before 2026-11 only the first was read, which left 21 of the
        // 60 Hot Wheels years — most of 1970–1998 — yielding no castings at all.
        foreach ($this->galleryTitles($wikitext) as $title) {
            $titles[$title] = true;
        }

        foreach ($this->rowTemplateTitles($wikitext) as $title) {
            $titles[$title] = true;
        }

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
     * The early years are photo galleries, not tables: one line per car,
     * `Image:Mantis.jpg|[[Mantis]]|link=Mantis`. The caption's link is
     * preferred and `link=` is the fallback: where they disagree the caption is
     * the more specific one — `[[Ambulance (1970)]]|link=Ambulance` is a casting
     * article captioned over a link to an unrelated page.
     *
     * @return list<string>
     */
    private function galleryTitles(string $wikitext): array
    {
        preg_match_all('/<gallery[^>]*>(.*?)<\/gallery>/is', $wikitext, $galleries);

        $titles = [];

        foreach ($galleries[1] as $gallery) {
            foreach (preg_split('/\R/', $gallery) ?: [] as $line) {
                $parts = explode('|', $line);

                // The first part is always the image; a line with nothing after
                // it is a bare picture with no car attached.
                array_shift($parts);
                $linkParameter = null;

                foreach ($parts as $index => $part) {
                    if (preg_match('/^\s*link\s*=\s*(.+?)\s*$/i', $part, $matches) === 1) {
                        $linkParameter = $matches[1];
                        unset($parts[$index]);
                    }
                }

                $target = array_key_first(Wikitext::links(implode('|', $parts))) ?? $linkParameter;

                if ($target !== null && ($title = $this->title(str_replace('_', ' ', $target))) !== null) {
                    $titles[] = $title;
                }
            }
        }

        return $titles;
    }

    /**
     * The late-1990s years render each row from a template —
     * `{{List0000White|16662|509|[[Firebird Funny Car (1997)|Firebird Funny
     * Car]]|1997 First Editions|01/12|}}` — so the table has no header row to
     * find a casting column by. Every argument is plain text except the model
     * name, which is the one link, so the links inside the call are the castings.
     *
     * @return list<string>
     */
    private function rowTemplateTitles(string $wikitext): array
    {
        preg_match_all('/\{\{\s*List[^|{}]*\|((?:[^{}]|\{\{[^{}]*\}\})*)\}\}/i', $wikitext, $calls);

        $titles = [];

        foreach ($calls[1] as $arguments) {
            foreach (array_keys(Wikitext::links($arguments)) as $target) {
                if (($title = $this->title($target)) !== null) {
                    $titles[] = $title;
                }
            }
        }

        return $titles;
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

    /**
     * The Hot Wheels index title. Other wikis name theirs through
     * Wiki::yearIndexTitle().
     */
    public static function titleForYear(int $year): string
    {
        return Wiki::hotWheels()->yearIndexTitle($year);
    }

    /**
     * A link target reduced to the article it points at, or null if it isn't one
     * a casting could live at.
     */
    private function title(string $target): ?string
    {
        // A section link still points at the article that holds it. Underscores
        // are spaces to MediaWiki — `[[Fisker_Karma_(2011)|…]]` is the article
        // "Fisker Karma (2011)" — and left in, one article arrives under two
        // titles and is imported twice or reported missing under one of them.
        $title = trim(str_replace('_', ' ', explode('#', $target, 2)[0]));

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
