<?php

namespace App\Models;

use App\Models\Concerns\HasApprovalStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A toy manufacturer's brand (Hot Wheels, Matchbox, Tomica, ...).
 */
#[Fillable(['name', 'parent_company'])]
class Brand extends Model
{
    use HasApprovalStatus, HasFactory;

    /**
     * There is deliberately no castings() relation. A casting has no brand — it's
     * one physical tool, and the brand that sold it is a per-release fact. The
     * question worth asking is "castings this brand has *sold*", which runs
     * brand -> series -> releases -> casting_versions -> castings; build it as an
     * explicit query (or off the search index) when a browse screen needs it,
     * rather than pretending it's a HasMany.
     */
    public function series(): HasMany
    {
        return $this->hasMany(Series::class);
    }

    /**
     * Sister brands under the same parent company (Hot Wheels and Matchbox are
     * both Mattel), which is what lets the pipeline reason about a casting being
     * reused across a company's brands.
     */
    public function siblings(): HasMany
    {
        return $this->hasMany(self::class, 'parent_company', 'parent_company')
            ->whereKeyNot($this->getKey());
    }
}
