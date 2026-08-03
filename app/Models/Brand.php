<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A die-cast brand: Hot Wheels, Matchbox, Corgi, Inno64, Mini GT, Tomica...
 *
 * Onboarding a brand must never require a schema change — anything specific to
 * one brand (numbering scheme, card format, sub-line quirks) belongs in the
 * metadata JSON column, not a new column.
 *
 * `company` is what makes sister brands recognisable: Hot Wheels and Matchbox
 * both carry "Mattel", which is why a casting can legitimately appear under
 * both.
 */
#[Fillable(['name', 'slug', 'company', 'country', 'founded_year', 'metadata'])]
#[RouteKey('slug')]
class Brand extends Model
{
    protected function casts(): array
    {
        return [
            'founded_year' => 'integer',
            'metadata' => 'array',
        ];
    }

    /** @return HasMany<Series, $this> */
    public function series(): HasMany
    {
        return $this->hasMany(Series::class);
    }

    /** @return HasMany<Release, $this> */
    public function releases(): HasMany
    {
        return $this->hasMany(Release::class);
    }

    /**
     * Castings this brand has actually sold, derived from releases rather than
     * kept in a separate pivot table — releases are the source of truth for
     * "brand X sold casting Y", so there is nothing to keep in sync.
     *
     * @return BelongsToMany<Casting, $this>
     */
    public function castings(): BelongsToMany
    {
        return $this->belongsToMany(Casting::class, 'releases')->distinct();
    }

    /** Other brands under the same parent company (Hot Wheels <-> Matchbox). */
    #[Scope]
    protected function sisterBrands(Builder $query, self $brand): Builder
    {
        return $query->where('company', $brand->company)
            ->whereKeyNot($brand->getKey());
    }
}
