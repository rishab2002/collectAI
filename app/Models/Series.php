<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A themed release wave belonging to a brand, e.g. "Night Burnerz".
 *
 * Carries no year: a series can run for several years, and the year is what
 * defines a Release. Table name is set explicitly because the class name is
 * already plural.
 */
#[Fillable(['brand_id', 'name', 'slug', 'description', 'metadata'])]
#[RouteKey('slug')]
#[Table('series')]
class Series extends Model
{
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return HasMany<Release, $this> */
    public function releases(): HasMany
    {
        return $this->hasMany(Release::class);
    }

    /**
     * The full wave for one year — what the Comparison Agent diffs the DB
     * against when it is told "all Night Burnerz castings for 2011".
     *
     * @return HasMany<Release, $this>
     */
    public function releasesForYear(int $year): HasMany
    {
        return $this->releases()->where('year', $year);
    }
}
