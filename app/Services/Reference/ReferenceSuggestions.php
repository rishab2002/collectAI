<?php

namespace App\Services\Reference;

use App\Models\Brand;
use App\Models\Casting;
use App\Models\ReferenceItem;
use App\Models\Series;
use App\Services\AI\CastingIdentity;
use App\Services\AI\LocalMetadataAgent;
use App\Services\Recognition\ColourFamilies;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the Add a car form offers: every brand, main line and series the
 * catalog and the reference list know, and "find the model" — search the
 * reference products and pre-fill the form from one. Admin-only; nothing here
 * is shown to the public.
 */
final class ReferenceSuggestions
{
    public function __construct(
        private readonly ReferenceLabels $labels,
        private readonly LocalMetadataAgent $vehicles,
    ) {}

    /**
     * @return list<string>
     */
    public function brands(): array
    {
        return $this->merge(
            Brand::query()->pluck('name'),
            ReferenceItem::query()->whereNotNull('brand')->distinct()->pluck('brand'),
        );
    }

    /**
     * Main lines: catalog series that have sub-series, and reference lines.
     *
     * @return list<string>
     */
    public function lines(?string $brand = null): array
    {
        $catalog = Series::query()->whereNull('parent_id')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('series as c')->whereColumn('c.parent_id', 'series.id'))
            ->when($brand, fn ($q) => $q->whereHas('brand', fn ($b) => $b->whereRaw('LOWER(name) = LOWER(?)', [$brand])))
            ->pluck('name');

        $reference = ReferenceItem::query()->whereNotNull('line')
            ->when($brand, fn ($q) => $q->whereRaw('LOWER(brand) = LOWER(?)', [$brand]))
            ->distinct()->pluck('line');

        return $this->merge($catalog, $reference);
    }

    /**
     * Sub-series for one brand (a datalist of every series of every brand
     * would be tens of thousands of options).
     *
     * @return list<string>
     */
    public function series(?string $brand): array
    {
        if (blank($brand)) {
            return [];
        }

        return $this->merge(
            Series::query()->whereHas('brand', fn ($b) => $b->whereRaw('LOWER(name) = LOWER(?)', [$brand]))->distinct()->pluck('name'),
            ReferenceItem::query()->whereRaw('LOWER(brand) = LOWER(?)', [$brand])->whereNotNull('series')->distinct()->pluck('series'),
            5000,
        );
    }

    /**
     * Reference products matching a name or an item code; each says whether
     * the catalog already has its casting.
     *
     * @return Collection<int, array{item: ReferenceItem, casting: ?Casting}>
     */
    public function search(string $query, int $limit = 20): Collection
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return collect();
        }

        $items = ReferenceItem::query()
            ->where(fn ($q) => $q
                ->whereRaw('LOWER(item_code) = LOWER(?)', [$query])
                ->orWhere('name', 'ilike', '%'.addcslashes($query, '%_\\').'%'))
            ->orderByRaw('CASE WHEN LOWER(item_code) = LOWER(?) THEN 0 ELSE 1 END', [$query])
            ->orderByRaw('length(name)')
            ->limit($limit)
            ->get();

        return $items->map(fn (ReferenceItem $item): array => ['item' => $item, 'casting' => $this->existingCasting($item)]);
    }

    /**
     * The catalog casting a reference product is certainly a version of, if
     * any — so the form sends the admin to add a version there instead of
     * minting a duplicate casting.
     */
    public function existingCasting(ReferenceItem $item): ?Casting
    {
        $label = $this->labels->for($item);

        if (! $label['matched']) {
            return null;
        }

        [$source, $key] = explode('|', $label['casting_key'], 2);

        if (str_starts_with($source, 'id:')) {
            return Casting::find((int) substr($source, 3));
        }

        $id = DB::table('record_sources')->where('source', $source)->where('source_key', $key)
            ->where('sourceable_type', (new Casting)->getMorphClass())->value('sourceable_id');

        return $id ? Casting::find($id) : null;
    }

    /**
     * Form defaults from a reference product. The make and model come from the
     * local vehicle dictionary when it is sure; the admin reviews everything
     * before saving.
     *
     * @return array<string, mixed>
     */
    public function prefill(ReferenceItem $item): array
    {
        $name = $item->name;
        $colour = null;

        // "Hummer H3 (Yellow)" → name "Hummer H3", colour "Yellow" — but only
        // a bracket that reads as a colour; "(R34)" stays part of the name.
        if (preg_match('/^(.*\S)\s*\(([^()]+)\)\s*$/', $name, $m) && app(ColourFamilies::class)->familyOf($m[2]) !== null) {
            [$name, $colour] = [$m[1], trim($m[2])];
        }

        $identity = $this->vehicles->identifyName(0, $name);

        return array_filter([
            'reference_item_id' => $item->id,
            'casting_name' => $name,
            'is_fantasy' => $identity->kind === CastingIdentity::KIND_FANTASY,
            'make' => $identity->kind === CastingIdentity::KIND_REAL ? $identity->make : null,
            'model' => $identity->kind === CastingIdentity::KIND_REAL ? $identity->model : null,
            'brand' => $item->brand,
            'line' => $item->line,
            'series' => $item->series ?? $item->line,
            'year' => $item->year,
            'toy_number' => $item->item_code,
            'color' => $colour,
        ], fn ($v) => $v !== null && $v !== false);
    }

    /**
     * @return list<string>
     */
    private function merge(Collection $a, Collection $b, int $limit = 2000): array
    {
        $seen = [];

        foreach ($a->concat($b) as $name) {
            $name = trim((string) $name);

            if ($name !== '') {
                $seen[mb_strtolower($name)] ??= $name;
            }
        }

        $names = array_values($seen);
        natcasesort($names);

        return array_slice(array_values($names), 0, $limit);
    }
}
