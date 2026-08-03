<?php

namespace App\Models;

use App\Models\Concerns\HasApprovalStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

/**
 * One physical tool cut by a toy manufacturer. A Casting carries no brand: which
 * brand sold it is a fact about each Release, reached as
 * release -> series -> brand. Two brands that each tool their own RX-7 are two
 * Casting rows pointing at the same VehicleModel; one tool sold under two sister
 * brands is a single Casting row with releases under each brand's series.
 */
#[Fillable(['model_id', 'is_fantasy', 'name', 'notes'])]
class Casting extends Model
{
    use HasApprovalStatus, HasFactory;

    protected function casts(): array
    {
        return [
            'is_fantasy' => 'boolean',
        ];
    }

    /**
     * Null both for fantasy castings and for real castings whose model hasn't
     * been identified yet — check is_fantasy to tell those apart.
     */
    public function model(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'model_id');
    }

    /**
     * Reached through the model rather than stored on this row, so a casting's
     * make can never drift out of sync with its model. Null for fantasy castings,
     * same as model().
     */
    public function make(): HasOneThrough
    {
        return $this->hasOneThrough(
            Make::class,
            VehicleModel::class,
            'id',        // models.id, matched against castings.model_id
            'id',        // makes.id, matched against models.make_id
            'model_id',
            'make_id',
        );
    }

    public function versions(): HasMany
    {
        return $this->hasMany(CastingVersion::class);
    }

    public function isFantasy(): bool
    {
        return $this->is_fantasy;
    }

    /**
     * A real casting whose VehicleModel hasn't been resolved yet. This is the
     * Comparison Agent's queue: the car exists, the catalog just can't name it.
     */
    public function isUnidentified(): bool
    {
        return ! $this->is_fantasy && $this->model_id === null;
    }

    /**
     * The model is null for fantasy castings, which the walk skips — a made-up
     * car has no real-world parent to approve.
     *
     * Brand is deliberately absent: a casting has none. Approving a release
     * promotes its series, and the series promotes its brand, so nothing goes
     * live under an invisible brand.
     */
    public function approvalDependencies(): array
    {
        return [$this->model];
    }
}
