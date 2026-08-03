<?php

namespace App\Models;

use App\Models\Concerns\HasApprovalStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * A toy manufacturer's specific tooling of a vehicle, e.g. Hot Wheels'
 * "'93 Mazda RX-7". The tooling is what is reused; the paint and packaging
 * around it are Releases and Variations.
 *
 * Make/Model split: if the casting is based on a real production car it links
 * to both a Make and a VehicleModel even when the packaging prints only one
 * name. Fantasy/original castings keep both null and set is_fantasy — the flag
 * exists so "no real counterpart" is distinguishable from "not identified yet",
 * which a null make_id alone cannot express.
 *
 * `company` (Mattel) rather than a brand: the same tooling can be sold under
 * sister brands, so the owning brand is a property of each Release.
 */
#[Fillable([
    'make_id',
    'vehicle_model_id',
    'name',
    'slug',
    'company',
    'designer',
    'scale',
    'debut_year',
    'is_fantasy',
    'metadata',
    'status',
    'confidence_score',
])]
#[RouteKey('slug')]
class Casting extends Model
{
    use HasApprovalStatus;

    protected function casts(): array
    {
        return [
            'debut_year' => 'integer',
            'is_fantasy' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /**
     * Named vehicleMake() rather than make(): an instance method called make()
     * shadows Eloquent's static Model::make() helper, turning Casting::make([])
     * into a fatal "non-static method" error.
     *
     * @return BelongsTo<Make, $this>
     */
    public function vehicleMake(): BelongsTo
    {
        return $this->belongsTo(Make::class, 'make_id');
    }

    /** @return BelongsTo<VehicleModel, $this> */
    public function vehicleModel(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class);
    }

    /**
     * Retools of this tooling.
     *
     * @return HasMany<CastingVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(CastingVersion::class);
    }

    /** @return HasMany<Release, $this> */
    public function releases(): HasMany
    {
        return $this->hasMany(Release::class);
    }

    /**
     * Every brand this tooling has been sold under, read off the releases.
     *
     * @return BelongsToMany<Brand, $this>
     */
    public function brands(): BelongsToMany
    {
        return $this->belongsToMany(Brand::class, 'releases')->distinct();
    }

    /** @return HasManyThrough<Variation, Release, $this> */
    public function variations(): HasManyThrough
    {
        return $this->hasManyThrough(Variation::class, Release::class);
    }

    /** Castings with a real-world counterpart, i.e. eligible for Make/Model. */
    #[Scope]
    protected function realWorld(Builder $query): Builder
    {
        return $query->where('is_fantasy', false);
    }

    /** Real-world castings still missing their Make/Model split. */
    #[Scope]
    protected function unattributed(Builder $query): Builder
    {
        return $query->where('is_fantasy', false)->whereNull('vehicle_model_id');
    }
}
