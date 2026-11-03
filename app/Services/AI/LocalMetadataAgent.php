<?php

namespace App\Services\AI;

use App\Services\AI\Contracts\IdentifiesCastings;
use Illuminate\Support\Collection;

/**
 * The Make/Model split without a language model: the casting's name is looked
 * up in a hand-written dictionary of manufacturers and nameplates
 * (resources/data/vehicles.json). No network, no cost, and the same name gives
 * the same answer every time.
 *
 * It knows less than the Claude agent and says so: anything it can't place is
 * "unknown", which leaves the casting where it was. Three outcomes:
 *
 * - **Make and nameplate both in the name** ("'93 Mazda RX-7") — scored 0.93,
 *   above the importer's 0.9 bar, so it is published like any confident
 *   inference. Both words are literally there; the dictionary only confirms
 *   the nameplate belongs to that make.
 * - **Nameplate only, and only one make sells it** ("'67 Camaro") — scored
 *   0.85: written down, but held for an admin, because "only one make in our
 *   dictionary" is not "only one make in the world".
 * - **One of the dictionary's fantasy castings** (Bone Shaker) — fantasy, 0.95.
 *
 * Generations are never claimed: a name's model year doesn't say which
 * generation's years it falls in, and a wrong generation files a car under the
 * wrong row. The casting is filed under the bare nameplate.
 */
final class LocalMetadataAgent implements IdentifiesCastings
{
    /** @var list<array{make: string, model: string, alias: string, compact: string, length: int}>|null */
    private ?array $nameplates = null;

    /** @var array<string, string> normalised alias => make */
    private array $makes = [];

    /** @var array<string, array<string, true>> normalised nameplate or alias => makes using it */
    private array $sellers = [];

    /** @var array<string, true> */
    private array $needsMake = [];

    /** @var array<string, true> */
    private array $fantasy = [];

    public function __construct(private readonly ?string $path = null) {}

    public function identify(Collection $castings, array $knownMakes = []): array
    {
        $this->load();
        $answers = [];

        foreach ($castings as $casting) {
            $answers[$casting->getKey()] = $this->identifyName((int) $casting->getKey(), (string) $casting->name);
        }

        return $answers;
    }

    public function identifyName(int $id, string $name): CastingIdentity
    {
        $this->load();
        $normalised = self::normalise($name);
        $tokens = $normalised === '' ? [] : explode(' ', $normalised);

        if (isset($this->fantasy[$normalised])) {
            return $this->answer($id, $name, CastingIdentity::KIND_FANTASY, null, null, ['kind' => 0.95]);
        }

        // Every make named in the casting, earliest first: "Dodge Ram 1500"
        // is a Dodge, even though Ram is a make too.
        $named = [];

        foreach ($this->makes as $alias => $make) {
            if (($at = self::find($tokens, $alias)) !== null && (! isset($named[$make]) || $at < $named[$make])) {
                $named[$make] = $at;
            }
        }

        asort($named);

        foreach (array_keys($named) as $make) {
            if (($model = $this->longestNameplate($tokens, $make)) !== null) {
                return $this->answer($id, $name, CastingIdentity::KIND_REAL, $make, $model,
                    ['kind' => 0.95, 'make' => 0.97, 'model' => 0.93]);
            }
        }

        if ($named === []) {
            $model = $this->longestNameplate($tokens, null);

            if ($model !== null) {
                return $this->answer($id, $name, CastingIdentity::KIND_REAL, $model[0], $model[1],
                    ['kind' => 0.9, 'make' => 0.85, 'model' => 0.9]);
            }
        }

        return $this->answer($id, $name, CastingIdentity::KIND_UNKNOWN, null, null, []);
    }

    /**
     * The longest nameplate in the name. With a make: one of that make's. Without:
     * one only a single make sells, long enough not to be a common word.
     *
     * @param  list<string>  $tokens
     * @return ($make is null ? array{0: string, 1: string}|null : string|null)
     */
    private function longestNameplate(array $tokens, ?string $make): string|array|null
    {
        $best = null;

        foreach ($this->nameplates as $plate) {
            if ($make !== null && $plate['make'] !== $make) {
                continue;
            }

            if ($make === null && (
                count($this->sellers[$plate['alias']]) > 1
                || isset($this->needsMake[$plate['alias']]) || isset($this->needsMake[self::normalise($plate['model'])])
                || $plate['length'] < 4 || ctype_digit($plate['compact'])
            )) {
                continue;
            }

            if (($best === null || $plate['length'] > $best['length']) && self::find($tokens, $plate['alias']) !== null) {
                $best = $plate;
            }
        }

        if ($best === null) {
            return null;
        }

        return $make === null ? [$best['make'], $best['model']] : $best['model'];
    }

    /**
     * Where a phrase starts in the token list, on whole words only.
     *
     * Spacing may differ when there is a number in it — "RX-7", "RX7" and
     * "RX 7" are one nameplate, as are "GT40" and "GT 40". A phrase of letters
     * only must match word for word: run together, "Model S" would be found in
     * "Cancelled Models".
     *
     * @param  list<string>  $tokens
     */
    private static function find(array $tokens, string $phrase): ?int
    {
        $count = count($tokens);

        if (preg_match('/\d/', $phrase) !== 1) {
            $words = explode(' ', $phrase);
            $length = count($words);

            for ($start = 0; $start + $length <= $count; $start++) {
                if (array_slice($tokens, $start, $length) === $words) {
                    return $start;
                }
            }

            return null;
        }

        $want = str_replace(' ', '', $phrase);

        for ($start = 0; $start < $count; $start++) {
            $joined = '';

            for ($end = $start; $end < $count && strlen($joined) < strlen($want); $end++) {
                $joined .= $tokens[$end];

                if ($joined === $want) {
                    return $start;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, float>  $confidence
     */
    private function answer(int $id, string $name, string $kind, ?string $make, ?string $model, array $confidence): CastingIdentity
    {
        return new CastingIdentity(
            castingId: $id,
            castingName: $name,
            kind: $kind,
            make: $make,
            model: $model,
            chassisCode: null,
            generationStartYear: null,
            generationEndYear: null,
            confidence: $confidence + ['kind' => 0.0, 'make' => 0.0, 'model' => 0.0, 'generation' => 0.0],
        );
    }

    public static function normalise(string $text): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
        $ascii = str_replace('&', ' and ', mb_strtolower($ascii));
        // Apostrophes join rather than split: "Splittin' Image", "Fish'd".
        $ascii = preg_replace("/['’`\"]/", '', $ascii);

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $ascii));
    }

    private function load(): void
    {
        if ($this->nameplates !== null) {
            return;
        }

        $data = json_decode(file_get_contents($this->path ?? config('catalog.ai.vehicles')), true, flags: JSON_THROW_ON_ERROR);
        $this->nameplates = [];

        foreach ($data['makes'] as $make) {
            foreach ([$make['name'], ...($make['aliases'] ?? [])] as $alias) {
                $this->makes[self::normalise($alias)] = $make['name'];
            }

            foreach ($make['models'] as $model) {
                $model = is_string($model) ? ['name' => $model] : $model;

                foreach ([$model['name'], ...($model['aliases'] ?? [])] as $alias) {
                    $normalised = self::normalise($alias);
                    $this->sellers[$normalised][$make['name']] = true;
                    $this->nameplates[] = [
                        'make' => $make['name'],
                        'model' => $model['name'],
                        'alias' => $normalised,
                        'compact' => str_replace(' ', '', $normalised),
                        'length' => strlen(str_replace(' ', '', $normalised)),
                    ];
                }
            }
        }

        foreach ($data['needs_make'] as $word) {
            $this->needsMake[self::normalise($word)] = true;
        }

        foreach ($data['fantasy'] as $name) {
            $this->fantasy[self::normalise($name)] = true;
        }
    }
}
