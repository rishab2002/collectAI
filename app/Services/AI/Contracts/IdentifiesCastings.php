<?php

namespace App\Services\AI\Contracts;

use App\Exceptions\MetadataFailedException;
use App\Models\Casting;
use App\Services\AI\CastingIdentity;
use Illuminate\Support\Collection;

/**
 * Names the real car behind each casting (the Make/Model split).
 *
 * Two implementations, chosen by catalog.ai.driver: LocalMetadataAgent (the
 * vehicle dictionary in resources/data/vehicles.json — the default) and
 * MetadataAgent (the Claude API). CastingIdentifier writes the answers the
 * same way whichever one gave them.
 */
interface IdentifiesCastings
{
    /**
     * @param  Collection<int, Casting>  $castings
     * @param  list<string>  $knownMakes
     * @return array<int, CastingIdentity> Keyed by casting id.
     *
     * @throws MetadataFailedException
     */
    public function identify(Collection $castings, array $knownMakes = []): array;
}
