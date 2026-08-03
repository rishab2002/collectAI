<?php

namespace App\Models;

use App\Models\Concerns\HasApprovalStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A real-world vehicle manufacturer (Mazda, Toyota, ...) — not a toy brand.
 */
#[Fillable(['name'])]
class Make extends Model
{
    use HasApprovalStatus, HasFactory;

    public function models(): HasMany
    {
        return $this->hasMany(VehicleModel::class);
    }
}
