<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A variation a user wants. `max_price` is what a price-drop notification
 * would compare incoming PriceHistory rows against.
 */
#[Fillable([
    'user_id',
    'variation_id',
    'priority',
    'max_price',
    'max_price_currency',
    'notes',
])]
class WishlistItem extends Model
{
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'max_price' => 'decimal:2',
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
