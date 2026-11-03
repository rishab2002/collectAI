<?php

namespace App\Services\Import\FandomWiki;

use App\Models\RecordSource;
use InvalidArgumentException;

/**
 * One Fandom diecast wiki, and the handful of facts that make it different from
 * the others.
 *
 * The Hot Wheels and Matchbox wikis share their page format — the same
 * `{{casting}}` infobox, the same thirteen-column Versions table, the same
 * `List of YYYY …` year indexes — so one parser and one importer serve both.
 * What differs is small and lives here: which site it is, which brand its
 * releases were sold under, which provenance source its keys are numbered
 * within, and how its editors write a Series cell.
 *
 * Adding a brand needs zero schema changes. Adding another Fandom wiki with this
 * page format needs one more factory method here.
 */
final class Wiki
{
    /**
     * @param  string  $source  The `record_sources.source` its keys are numbered within. One per site, never shared: page ids restart from 1 on every wiki.
     * @param  bool  $compoundSeries  Whether "[[1-100]]: [[Sports Cars]]" names one series ("1-100: Sports Cars") rather than the first link alone. See CastingPageParser::series().
     */
    private function __construct(
        public readonly string $key,
        public readonly string $host,
        public readonly string $source,
        public readonly string $brand,
        public readonly ?string $parentCompany,
        private readonly string $indexBrand,
        public readonly bool $compoundSeries,
    ) {}

    public static function hotWheels(): self
    {
        return new self(
            key: 'hot-wheels',
            host: 'hotwheels.fandom.com',
            source: RecordSource::SOURCE_HOT_WHEELS_FANDOM,
            brand: 'Hot Wheels',
            parentCompany: 'Mattel',
            indexBrand: 'Hot Wheels',
            // Off, and must stay off: 1,002 Hot Wheels rows carry text beside the
            // series link ("[[2023 Hot Wheels Legends Tour]] Trophy Car"), and
            // keying those by name instead of by link would mint a second series
            // beside every one an earlier import already created.
            compoundSeries: false,
        );
    }

    public static function matchbox(): self
    {
        return new self(
            key: 'matchbox',
            host: 'matchbox.fandom.com',
            source: RecordSource::SOURCE_MATCHBOX_FANDOM,
            brand: 'Matchbox',
            // Mattel since 1997. Hot Wheels and Matchbox are sister brands, which
            // is exactly what `brands.parent_company` is for.
            parentCompany: 'Mattel',
            indexBrand: 'Matchbox',
            // On: 5,932 Matchbox rows name the range and its segment together —
            // "[[1-75]]: [[Wheeled Envy]]", "5-Pack: [[…|VIP Parking]]" — and the
            // first link alone would file every mainline car under "1-75".
            compoundSeries: true,
        );
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return ['hot-wheels', 'matchbox'];
    }

    public static function fromKey(string $key): self
    {
        return match ($key) {
            'hot-wheels' => self::hotWheels(),
            'matchbox' => self::matchbox(),
            default => throw new InvalidArgumentException(
                "Unknown wiki “{$key}”. Known: ".implode(', ', self::keys()).'.'
            ),
        };
    }

    public function yearIndexTitle(int $year): string
    {
        return "List of {$year} {$this->indexBrand}";
    }

    public function apiEndpoint(): string
    {
        return "https://{$this->host}/api.php";
    }

    public function articleUrl(string $title): string
    {
        return "https://{$this->host}/wiki/".str_replace('%2F', '/', rawurlencode(str_replace(' ', '_', $title)));
    }

    public function searchUrl(string $query): string
    {
        return "https://{$this->host}/wiki/Special:Search?query=".rawurlencode($query);
    }

    /**
     * Where the site's XML dump is published. The import reads the dump rather
     * than crawling: one download for every page.
     */
    public function dumpUrl(): string
    {
        $name = explode('.', $this->host)[0];

        return sprintf('https://s3.amazonaws.com/wikia_xml_dumps/%s/%s/%s_pages_current.xml.7z', $name[0], substr($name, 0, 2), $name);
    }
}
