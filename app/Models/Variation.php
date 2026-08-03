<?php

namespace App\Models;

use App\Models\Concerns\HasApprovalStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * What makes one unit of a release different from another: body colour,
 * finish, wheel/rim type, tampo, interior, window tint, base colour.
 *
 * Each distinguishing combination is its own record — these are columns, not a
 * free-text note, so the Comparison Agent can diff them. Anything a single
 * brand cares about and others do not (chase flags, exclusive markings) goes in
 * metadata so adding a brand never needs a migration.
 *
 * This is the level collectors actually own, so price history, photos,
 * collections and wishlists all hang off here.
 */
#[Fillable([
    'release_id',
    'name',
    'body_color',
    'finish',
    'wheel_type',
    'tampo',
    'interior_color',
    'window_color',
    'base_color',
    'base_material',
    'country_of_manufacture',
    'is_chase',
    'notes',
    'metadata',
    'status',
    'confidence_score',
])]
class Variation extends Model
{
    use HasApprovalStatus;

    protected function casts(): array
    {
        return [
            'is_chase' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Release, $this> */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    /** @return HasMany<PriceHistory, $this> */
    public function priceHistory(): HasMany
    {
        return $this->hasMany(PriceHistory::class);
    }

    /** @return HasMany<Photo, $this> */
    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class);
    }

    /** @return HasOne<Photo, $this> */
    public function primaryPhoto(): HasOne
    {
        return $this->hasOne(Photo::class)->where('is_primary', true);
    }

    /** @return HasMany<CollectionItem, $this> */
    public function collectionItems(): HasMany
    {
        return $this->hasMany(CollectionItem::class);
    }

    /** @return HasMany<WishlistItem, $this> */
    public function wishlistItems(): HasMany
    {
        return $this->hasMany(WishlistItem::class);
    }

    /**
     * Users who own this variation.
     *
     * @return BelongsToMany<User, $this>
     */
    public function owners(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'collection_items')
            ->withPivot(['quantity', 'condition', 'acquired_at'])
            ->withTimestamps();
    }

    /**
     * Users who want this variation.
     *
     * @return BelongsToMany<User, $this>
     */
    public function wishers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'wishlist_items')
            ->withPivot(['priority', 'max_price'])
            ->withTimestamps();
    }
}
