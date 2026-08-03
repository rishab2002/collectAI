<?php

namespace App\Models;

use App\Enums\ItemCondition;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One collector's ownership of a variation. Also reachable as the pivot on
 * Variation::collectors() / User::collection(); this model exists for the cases
 * that query ownership directly rather than through either side.
 */
#[Fillable(['user_id', 'variation_id', 'quantity', 'condition', 'acquired_at'])]
class UserCollectionItem extends Model
{
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(Variation::class);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'condition' => ItemCondition::class,
            'acquired_at' => 'date',
        ];
    }
}
