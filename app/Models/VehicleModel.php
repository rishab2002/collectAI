<?php

namespace App\Models;

use App\Models\Concerns\HasApprovalStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A real-world vehicle model (RX-7, Civic, ...).
 *
 * Named VehicleModel rather than Model because `Model` is Eloquent's own base
 * class — the table keeps its natural name and the class carries the explicit
 * #[Table] mapping, rather than distorting the schema to suit PHP.
 */
#[Table('models')]
#[Fillable(['make_id', 'name', 'chassis_code'])]
class VehicleModel extends Model
{
    use HasApprovalStatus, HasFactory;

    public function make(): BelongsTo
    {
        return $this->belongsTo(Make::class);
    }

    public function castings(): HasMany
    {
        return $this->hasMany(Casting::class, 'model_id');
    }

    public function approvalDependencies(): array
    {
        return [$this->make];
    }
}
