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

    /**
     * A real casting whose model nobody has identified is not publishable. Going
     * live would put a car in the catalog that can't be found by make or model
     * and can't be told apart from a fantasy casting — and once it's live,
     * nothing flags it for a second look.
     *
     * The fix is a human one either way: identify the model, or say it's
     * fantasy. Neither is something approving can decide.
     */
    public function approvalBlocker(): ?string
    {
        return $this->isUnidentified()
            ? "Casting “{$this->name}” has no vehicle model. Identify the real make and model, or mark it as a fantasy casting, before approving."
            : null;
    }

    /**
     * Rejecting a proposal for a casting means one of two different things, and
     * which one depends on where the casting came from.
     *
     * A casting the capture pipeline invented is rejected outright — the default.
     * A casting that arrived from an outside source exists independently of
     * anything inferred about it: the wiki states the tool and its whole
     * Versions table, and the only thing the Metadata Agent added was the
     * attribution. Rejecting "this is a Ford Thunderbird" has to retract that
     * attribution and nothing else. Marking the row rejected instead would throw
     * away a real, sourced casting — and, because approval walks upward, quietly
     * strand every release and variation hanging off it with a parent that can
     * never go live.
     *
     * Retracting puts it back where the importer left it: a real car nobody has
     * named yet. CastingIdentifier::outstanding() deliberately won't pick it up
     * again, because the rejected proposal it leaves behind is a ruling, not a
     * gap — re-inferring would hand the admin back the claim they just refused.
     */
    public function rejectProposed(): void
    {
        if (! $this->source()->exists()) {
            $this->markRejected();

            return;
        }

        $this->forceFill(['model_id' => null, 'is_fantasy' => false])->save();
    }
}
