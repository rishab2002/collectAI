<?php

namespace App\Models;

use App\Enums\ItemCondition;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A variation a user owns.
 *
 * A model rather than a bare pivot because it carries its own data — how many,
 * what condition, what they paid — and users hold duplicates of the same
 * variation in different conditions.
 */
#[Fillable([
    'user_id',
    'variation_id',
    'quantity',
    'condition',
    'purchase_price',
    'purchase_currency',
    'acquired_at',
    'notes',
])]
class CollectionItem extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'condition' => ItemCondition::class,
            'purchase_price' => 'decimal:2',
            'acquired_at' => 'date',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Variation, $this> */
    public function variation(): BelongsTo
    {
        return $this->belongsTo(Variation::class);
    }
}
