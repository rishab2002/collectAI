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
#[Fillable(['make_id', 'name', 'chassis_code', 'generation_start_year', 'generation_end_year'])]
class VehicleModel extends Model
{
    use HasApprovalStatus, HasFactory;

    public function make(): BelongsTo
    {
        return $this->belongsTo(Make::class);
    }

    /**
     * The generation, written the way a person reads it: "C3 (1968–1982)".
     *
     * A bare chassis code is unreadable to anyone who isn't already a marque
     * specialist, and a bare year range doesn't say what enthusiasts call it, so
     * the label carries whichever of the two this row has and both when it has
     * both. Null when the generation was never established — which is a real
     * state, not a missing value, and the catalogue should say so rather than
     * print an empty pair of brackets.
     *
     * An en dash, not a hyphen: this is a range in prose, not a subtraction.
     */
    public function generationLabel(): ?string
    {
        $years = match (true) {
            $this->generation_start_year === null => null,
            // Still in production. "1997–" reads as unfinished rather than
            // ongoing, so it's spelled out.
            $this->generation_end_year === null => "{$this->generation_start_year}–present",
            $this->generation_end_year === $this->generation_start_year => (string) $this->generation_start_year,
            default => "{$this->generation_start_year}–{$this->generation_end_year}",
        };

        return match (true) {
            $this->chassis_code !== null && $years !== null => "{$this->chassis_code} ({$years})",
            $this->chassis_code !== null => $this->chassis_code,
            default => $years,
        };
    }

    /**
     * Nameplate plus generation — what distinguishes this row from its siblings.
     * "Corvette · C3 (1968–1982)".
     */
    public function fullName(): string
    {
        return ($label = $this->generationLabel()) === null
            ? $this->name
            : "{$this->name} · {$label}";
    }

    public function castings(): HasMany
    {
        return $this->hasMany(Casting::class, 'model_id');
    }

    public function approvalDependencies(): array
    {
        return [$this->make];
    }

    protected function casts(): array
    {
        return [
            'generation_start_year' => 'integer',
            'generation_end_year' => 'integer',
        ];
    }
}
