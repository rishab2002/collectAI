<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * When a variation's price was last looked up, and the outcome — including
 * "none", so an unpriceable car isn't re-queried daily. See the migration.
 */
#[Fillable(['variation_id', 'checked_at', 'outcome', 'calls'])]
class PriceCheck extends Model
{
    public function variation(): BelongsTo
    {
        return $this->belongsTo(Variation::class);
    }

    protected function casts(): array
    {
        return ['checked_at' => 'datetime'];
    }
}
