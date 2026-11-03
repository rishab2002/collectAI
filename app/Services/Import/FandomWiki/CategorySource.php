<?php

namespace App\Services\Import\FandomWiki;

/**
 * A source that can list a wiki category's articles, with their categories.
 *
 * For wikis organised by category rather than by year index — the Tomica wiki
 * files every release under its toy line ("Tomica Limited Vintage Neo") and its
 * year ("2019 Tomica"), and those two categories are most of the facts an
 * article states in structured form.
 */
interface CategorySource
{
    /**
     * @return iterable<WikiPage> each carrying its categories
     */
    public function categoryMembers(string $category): iterable;
}
