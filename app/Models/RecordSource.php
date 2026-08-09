<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Where an imported catalog record came from, and which revision of it we read.
 *
 * The presence of one of these is what marks a record as something a source
 * states rather than something the pipeline inferred — see the import decisions
 * in CLAUDE.md. Facts the wiki prints go live through the importer and carry a
 * RecordSource; the Make/Model split and is_fantasy are inferences and go
 * through SubmissionProposal instead.
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
     * hotwheels.fandom.com. The only source there is so far; the column is
     * deliberately un-CHECKed so adding the next one needs no migration.
     */
    public const SOURCE_HOT_WHEELS_FANDOM = 'hotwheels_fandom';

    /**
     * The catalog record this describes — a Casting, Release, Variation, and so on.
     */
    public function sourceable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The key for a record that *is* a source page: one casting per article.
     */
    public static function pageKey(int|string $pageId): string
    {
        return "page:{$pageId}";
    }

    /**
     * The key for a series.
     *
     * Keyed by the series itself rather than by a page, because a series is
     * shared: every 2011 Nightburnerz car names the same one, and keying it to
     * whichever casting page happened to mention it first would make the second
     * page's import collide with the first.
     */
    public static function seriesKey(string $name): string
    {
        return 'series:'.self::identity($name);
    }

    /**
     * The key for a release read off a casting page's ==Versions== table.
     *
     * Built from the release's own identity — the year and series it was sold in
     * — rather than from its row number. The settled requirement is that a key
     * identifies one *record* rather than one page, and a row number satisfies
     * that only until somebody inserts a row: every key below it then shifts by
     * one, and the next import silently resolves each release to its neighbour.
     * Year plus series is what the release actually is, and it survives the page
     * being reordered, split or extended.
     */
    public static function releaseKey(int|string $pageId, int $year, string $series): string
    {
        return self::pageKey($pageId).":release:{$year}:".self::identity($series);
    }

    /**
     * The key for one variation under that release.
     *
     * Positional within its release, which is a far smaller and more stable
     * scope than the page: adding a 2015 release doesn't renumber the 2011 one's
     * variations. The importer still resolves by natural key first and treats
     * this as a fallback, so a genuine reshuffle costs an update rather than a
     * duplicate.
     */
    public static function variationKey(int|string $pageId, int $year, string $series, int $position): string
    {
        return self::releaseKey($pageId, $year, $series).":variation:{$position}";
    }

    /**
     * The name as the source writes it, with only whitespace normalised.
     *
     * It deliberately does *not* slug. Slugging looked tidier and was wrong: it
     * folded case and collapsed punctuation, so "Monster Jam: Monster Duo" and
     * "Monster Jam - Monster Duo" — two distinct series the wiki lists
     * separately — produced one key, and the second import to reach them died on
     * the unique constraint. A source key has one job, which is to identify one
     * record; any transform that can map two records onto one key can only fail,
     * either loudly like that or silently by overwriting.
     *
     * Keeping it identical to the name also keeps it in step with how the
     * importer resolves a series, which is an exact match on that name.
     */
    private static function identity(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return $value === '' ? 'unknown' : $value;
    }

    #[Scope]
    protected function fromSource(Builder $query, string $source): void
    {
        $query->where('source', $source);
    }

    /**
     * Records last read before the given moment — what a refresh run picks up.
     * Matches the (source, imported_at) index when chained onto fromSource().
     */
    #[Scope]
    protected function staleSince(Builder $query, \DateTimeInterface $moment): void
    {
        $query->where('imported_at', '<', $moment);
    }

    protected function casts(): array
    {
        return [
            'imported_at' => 'datetime',
        ];
    }
}
