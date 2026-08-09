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
     * Defaults to a casting imported from a wiki article, which is the shape the
     * importer produces most of. The page id doubles as the source key so a
     * factory-built row is self-consistent without the caller doing anything.
     */
    public function definition(): array
    {
        $pageId = fake()->unique()->numberBetween(1000, 99999);

        return [
            'sourceable_type' => Casting::class,
            'sourceable_id' => Casting::factory(),
            'source' => RecordSource::SOURCE_HOT_WHEELS_FANDOM,
            'source_key' => RecordSource::pageKey($pageId),
            'source_url' => "https://hotwheels.fandom.com/wiki/?curid={$pageId}",
            'source_revision_id' => fake()->numberBetween(100000, 999999),
            'imported_at' => now(),
        ];
    }

    public function forSourceable(Model $sourceable): static
    {
        return $this->state([
            'sourceable_type' => $sourceable->getMorphClass(),
            'sourceable_id' => $sourceable->getKey(),
        ]);
    }

    public function fromRevision(int $revisionId): static
    {
        return $this->state(['source_revision_id' => $revisionId]);
    }

    /**
     * A source that doesn't version its content, so nothing can establish
     * whether this row is still current. Its own state rather than
     * fromRevision(null), because "no revision" is a fact about the source, not
     * a missing argument.
     */
    public function unversioned(): static
    {
        return $this->state(['source_revision_id' => null]);
    }

    /**
     * One row of a multi-row page — a single line of a casting article's
     * Versions table, keyed under the page it was read from.
     */
    public function row(string $pageKey, int $row): static
    {
        return $this->state(['source_key' => RecordSource::rowKey($pageKey, $row)]);
    }
}
