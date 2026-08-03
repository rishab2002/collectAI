<?php

namespace App\Models;

use App\Models\Concerns\HasApprovalStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A specific year a casting was sold in stores under a given brand, optionally
 * as part of a series. A casting has many releases across years.
 *
 * brand_id is stored here rather than only reached through the series because
 * series_id is nullable — mainline/basic releases belong to no themed wave —
 * and because "which brand sold this" is the single most common filter in the
 * catalog. It is also what lets Casting::brands() and Brand::castings() use
 * this table as their pivot.
 *
 * What the unit physically looks like is not here; that is a Variation.
 */
#[Fillable([
    'casting_id',
    'casting_version_id',
    'brand_id',
    'series_id',
    'year',
    'collector_number',
    'series_position',
    'series_size',
    'region',
    'metadata',
    'status',
    'confidence_score',
])]
class Release extends Model
{
    use HasApprovalStatus;

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'series_position' => 'integer',
            'series_size' => 'integer',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Casting, $this> */
    public function casting(): BelongsTo
    {
        return $this->belongsTo(Casting::class);
    }

    /**
     * Null unless the release used a retooled version of the casting.
     *
     * @return BelongsTo<CastingVersion, $this>
     */
    public function castingVersion(): BelongsTo
    {
        return $this->belongsTo(CastingVersion::class);
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Null for mainline/basic releases that belong to no themed series.
     *
     * @return BelongsTo<Series, $this>
     */
    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class);
    }

    /** @return HasMany<Variation, $this> */
    public function variations(): HasMany
    {
        return $this->hasMany(Variation::class);
    }

    #[Scope]
    protected function forYear(Builder $query, int $year): Builder
    {
        return $query->where('year', $year);
    }

    /** "3/10" as printed on the card, when the series size is known. */
    public function seriesPositionLabel(): ?string
    {
        if ($this->series_position === null) {
            return null;
        }

        return $this->series_size
            ? "{$this->series_position}/{$this->series_size}"
            : (string) $this->series_position;
    }
}
