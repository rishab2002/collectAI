<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A real-world vehicle (RX-7, LFA, Mustang) belonging to a Make.
 *
 * Named VehicleModel rather than Model so it does not collide with
 * Illuminate\Database\Eloquent\Model in every file that touches it; the table
 * is vehicle_models for the same reason.
 *
 * One real Make/Model can be cast by many brands — Hot Wheels, Matchbox and
 * GreenLight may each tool their own RX-7 — so the Casting is brand-specific
 * while this record is not. Prefer recording the chassis code (FD3S), since one
 * nameplate spans multiple real generations.
 */
#[Fillable([
    'make_id',
    'name',
    'slug',
    'chassis_code',
    'generation',
    'production_start_year',
    'production_end_year',
    'body_style',
    'metadata',
])]
#[RouteKey('slug')]
class VehicleModel extends Model
{
    protected function casts(): array
    {
        return [
            'production_start_year' => 'integer',
            'production_end_year' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * Named vehicleMake() rather than make(): an instance method called make()
     * shadows Eloquent's static Model::make() helper, turning
     * VehicleModel::make([]) into a fatal "non-static method" error.
     *
     * @return BelongsTo<Make, $this>
     */
    public function vehicleMake(): BelongsTo
    {
        return $this->belongsTo(Make::class, 'make_id');
    }

    /** @return HasMany<Casting, $this> */
    public function castings(): HasMany
    {
        return $this->hasMany(Casting::class);
    }

    /** "Mazda RX-7 (FD3S)" for display and for Metadata Agent matching. */
    public function fullName(): string
    {
        $name = trim(($this->vehicleMake?->name ?? '').' '.$this->name);

        return $this->chassis_code ? "{$name} ({$this->chassis_code})" : $name;
    }
}
