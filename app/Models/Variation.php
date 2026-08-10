<?php

namespace App\Models;

use App\Models\Concerns\HasApprovalStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * What makes one unit of a release distinct: finish, colour, rims, tampos, and
 * so on. This is the leaf collectors actually own, price, and photograph.
 */
#[Fillable([
    'release_id',
    'finish',
    'color',
    'rim_type',
    'tampo',
    'interior_color',
    'window_tint',
    'base_color',
    'toy_number',
    'country',
    'notes',
])]
class Variation extends Model
{
    use HasApprovalStatus, HasFactory;

    /**
     * The columns the database's uniqueness index covers, in its order.
     *
     * Kept here so application-side duplicate checks (the Comparison Agent) test
     * exactly the same attribute set the DB enforces — if the two ever disagree,
     * the agent proposes a variation that then fails to insert.
     *
     * @var list<string>
     */
    public const DISTINGUISHING_ATTRIBUTES = [
        'finish',
        'color',
        'rim_type',
        'tampo',
        'interior_color',
        'window_tint',
        'base_color',
        // The manufacturer's part number and the country of assembly are part
        // of identity, not description: two cars alike on every other column
        // but carrying different toy numbers are two variations, and without
        // these here the second one collides and is lost on insert.
        'toy_number',
        'country',
    ];

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    public function priceHistories(): HasMany
    {
        return $this->hasMany(PriceHistory::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class);
    }

    public function primaryPhoto(): HasOne
    {
        return $this->hasOne(Photo::class)->where('is_primary', true);
    }

    /**
     * Collectors who own this variation. The pivot carries quantity/condition, so
     * multiple copies are a quantity bump rather than repeated rows.
     */
    public function collectors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_collection_items')
            ->withPivot(['quantity', 'condition', 'acquired_at'])
            ->withTimestamps();
    }

    public function wishlistedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_wishlist_items')
            ->withTimestamps();
    }

    public function approvalDependencies(): array
    {
        return [$this->release];
    }

    /**
     * The same COALESCE(col, '') identity the DB's unique index builds, so a
     * proposed variation can be compared against existing ones in PHP without
     * NULL-vs-NULL producing a false "these differ".
     */
    public function attributeSignature(): string
    {
        return collect(self::DISTINGUISHING_ATTRIBUTES)
            ->map(fn (string $attribute): string => (string) $this->{$attribute})
            ->implode('|');
    }
}
