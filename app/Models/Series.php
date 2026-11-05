<?php

namespace App\Models;

use App\Models\Concerns\HasApprovalStatus;
use App\Support\LineNames;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A brand-scoped, reusable named wave ("Night Burnerz"). It carries no year of
 * its own — the same series name recurs across multiple years, and the year
 * lives on the Release.
 *
 * #[Table] is explicit because "series" is its own plural; leaving it to the
 * inflector would work today but silently depends on that staying true.
 */
#[Table('series')]
#[Fillable(['brand_id', 'parent_id', 'name'])]
class Series extends Model
{
    use HasApprovalStatus, HasFactory;

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function releases(): HasMany
    {
        return $this->hasMany(Release::class);
    }

    /**
     * The main line this sub-series belongs to — "Mainline" for "HW Art Cars",
     * "1-100" for Matchbox's "Sports Cars". Null for a line itself, and for a
     * sub-series nobody has classified yet.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * The sub-series inside a main line.
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * "Mainline / HW Art Cars", or the name alone when there is no line — or
     * when the series shares its line's name, which makes it the line's own
     * cars rather than a sub-series (the wiki links each year's mainline to its
     * own anchor, so there are many rows called "Mainline").
     */
    public function path(): string
    {
        if ($this->parent === null) {
            return $this->name;
        }

        if (mb_strtolower($this->parent->name) === mb_strtolower($this->name) || $this->isLineItself()) {
            return $this->parent->name;
        }

        return "{$this->parent->name} / {$this->name}";
    }

    /**
     * A name that is the line rather than a wave inside it — "2009 New
     * Models". See App\Support\LineNames for why, and
     * config/catalog_lines.collapse for which.
     */
    public function isLineItself(): bool
    {
        return LineNames::isLineItself($this->name);
    }

    /**
     * The line has to be public before a sub-series under it can be, or the
     * browse tree would hold a branch with no visible root.
     */
    public function approvalDependencies(): array
    {
        return [$this->brand, $this->parent];
    }
}
