<?php

namespace Database\Factories;

use App\Models\Casting;
use App\Models\RecordSource;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<RecordSource>
 */
class RecordSourceFactory extends Factory
{
    /**
     * Defaults to a live Casting imported from the Hot Wheels wiki — the shape
     * the importer produces most of. Live rather than pending because provenance
     * is the importer's gate: a row the wiki states goes live through it.
     */
    public function definition(): array
    {
        $pageId = fake()->unique()->numberBetween(1000, 999999);

        return [
            'sourceable_type' => Casting::class,
            'sourceable_id' => Casting::factory(),
            'source' => RecordSource::SOURCE_HOT_WHEELS_FANDOM,
            'source_key' => RecordSource::pageKey($pageId),
            'source_url' => "https://hotwheels.fandom.com/?curid={$pageId}",
            'source_revision_id' => fake()->numberBetween(1, 2000000),
            'imported_at' => now(),
        ];
    }

    /**
     * Attach the provenance to a specific record instead of the default casting.
     */
    public function forSourceable(Model $sourceable): static
    {
        return $this->state([
            'sourceable_type' => $sourceable->getMorphClass(),
            'sourceable_id' => $sourceable->getKey(),
        ]);
    }

    public function fromSource(string $source, string $sourceKey): static
    {
        return $this->state([
            'source' => $source,
            'source_key' => $sourceKey,
        ]);
    }

    public function atRevision(?int $revisionId): static
    {
        return $this->state(['source_revision_id' => $revisionId]);
    }

    public function importedAt(\DateTimeInterface $moment): static
    {
        return $this->state(['imported_at' => $moment]);
    }
}
