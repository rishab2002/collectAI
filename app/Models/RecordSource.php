<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Where an imported catalog record came from.
 *
 * Two jobs: it makes a re-import idempotent — (source, source_key) is unique, so
 * the importer upserts against it rather than checking first and racing — and it
 * carries the attribution the wiki's CC-BY-SA licence requires back to the page
 * the facts were read from, for the public catalog to surface.
 */
#[Fillable([
    'sourceable_type',
    'sourceable_id',
    'source',
    'source_key',
    'source_url',
    'source_revision_id',
    'imported_at',
])]
class RecordSource extends Model
{
    use HasFactory;

    /**
     * hotwheels.fandom.com. Named for the site rather than the brand because the
     * distinction that matters here is which body of data a key is numbered
     * within — a future Matchbox wiki would be a different source even though
     * both are Fandom.
     */
    public const SOURCE_HOT_WHEELS_FANDOM = 'hotwheels_fandom';

    /**
     * matchbox.fandom.com. A separate source from Hot Wheels for exactly the
     * reason above: both wikis number their pages from 1, so `page:12345` names
     * a different article on each, and the `(source, source_key)` unique index
     * is what keeps them apart.
     */
    public const SOURCE_MATCHBOX_FANDOM = 'matchbox_fandom';

    /**
     * The catalog record these facts were imported into.
     */
    public function sourceable(): MorphTo
    {
        return $this->morphTo();
    }

    #[Scope]
    protected function fromSource(Builder $query, string $source): void
    {
        $query->where('source', $source);
    }

    /**
     * Records imported from a revision older than the one now available, which
     * is the set a re-import has to revisit. Rows with no revision recorded are
     * included: a source that doesn't version itself can never be shown to be
     * current, and re-reading it is cheaper than assuming it hasn't moved.
     */
    #[Scope]
    protected function staleAgainst(Builder $query, string $source, int $revisionId): void
    {
        $query->where('source', $source)
            ->where(function (Builder $query) use ($revisionId): void {
                $query->whereNull('source_revision_id')
                    ->orWhere('source_revision_id', '<', $revisionId);
            });
    }

    /**
     * The key identifying one row of a multi-row page — a single line of a
     * casting article's Versions table. Built here rather than at each call site
     * so the page key and its row keys can never drift apart.
     */
    public static function rowKey(string $pageKey, int $row): string
    {
        return "{$pageKey}:row:{$row}";
    }

    public static function pageKey(int $pageId): string
    {
        return "page:{$pageId}";
    }

    protected function casts(): array
    {
        return [
            'source_revision_id' => 'integer',
            'imported_at' => 'datetime',
        ];
    }
}
