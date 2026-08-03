<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * A real-world vehicle manufacturer (Mazda, Lexus, Ford).
 *
 * Real-world, not toy-world: the toy manufacturer is a Brand. Only castings
 * based on real vehicles link here — fantasy castings leave make_id null.
 */
#[Fillable(['name', 'slug', 'country', 'metadata'])]
#[RouteKey('slug')]
class Make extends Model
{
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /** @return HasMany<VehicleModel, $this> */
    public function vehicleModels(): HasMany
    {
        return $this->hasMany(VehicleModel::class);
    }

    /**
     * Castings link to the Make directly as well as through the VehicleModel,
     * so a casting whose exact model is unidentified can still be attributed.
     *
     * @return HasMany<Casting, $this>
     */
    public function castings(): HasMany
    {
        return $this->hasMany(Casting::class);
    }

    /** @return HasManyThrough<Casting, VehicleModel, $this> */
    public function castingsThroughModels(): HasManyThrough
    {
        return $this->hasManyThrough(Casting::class, VehicleModel::class);
    }
}
