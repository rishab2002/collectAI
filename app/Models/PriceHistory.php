<?php

namespace App\Models;

use App\Enums\ItemCondition;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One observed price for a variation — a completed sale or a listing, from
 * eBay, a marketplace feed or manual entry.
 *
 * Append-only: rows are observations, so charts read the series rather than a
 * "current price" field that would have to be recomputed on write.
 */
#[Fillable([
    'variation_id',
    'source',
    'source_listing_id',
    'url',
    'price',
    'currency',
    'condition',
    'is_sold',
    'observed_at',
])]
#[Table('price_histories')]
class PriceHistory extends Model
{
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'condition' => ItemCondition::class,
            'is_sold' => 'boolean',
            'observed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Variation, $this> */
    public function variation(): BelongsTo
    {
        return $this->belongsTo(Variation::class);
    }

    /** Completed sales only — asking prices skew the averages. */
    #[Scope]
    protected function sold(Builder $query): Builder
    {
        return $query->where('is_sold', true);
    }

    #[Scope]
    protected function inCondition(Builder $query, ItemCondition $condition): Builder
    {
        return $query->where('condition', $condition);
    }

    #[Scope]
    protected function observedSince(Builder $query, \DateTimeInterface $since): Builder
    {
        return $query->where('observed_at', '>=', $since);
    }
}
