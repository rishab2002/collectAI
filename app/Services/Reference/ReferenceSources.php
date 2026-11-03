<?php

namespace App\Services\Reference;

/**
 * Reads each permitted source through its bulk listing and yields one array
 * per product: source_key, brand, line, series, year, name, item_code, scale,
 * image_urls, page_url. Request counts, measured 2026-11-04, are in the
 * comments; one product page each would have been ~37,000 requests.
 */
final class ReferenceSources
{
    public const KEYS = ['hwpriceguide', 'tsm', 'inno64', 'para64', 'horizon'];

    /** Shopify CDN and WordPress serve resized copies; 448 px is what training uses. */
    private const IMAGE_WIDTH = 448;

    public function __construct(private readonly PoliteClient $http) {}

    /**
     * @return iterable<array<string, mixed>>
     */
    public function items(string $source, ?int $limit = null): iterable
    {
        $count = 0;

        $generator = match ($source) {
            'hwpriceguide' => $this->hwPriceGuide(),
            'tsm' => $this->miniGt(),
            'inno64' => $this->inno64(),
            'para64' => $this->shopify('https://paragonmodelstore.com', fn (array $p): bool => strcasecmp($p['vendor'], 'PARA64') === 0, 'Para64'),
            'horizon' => $this->shopify('https://horizondiecast.com', fn (array $p): bool => ! $this->isAccessory($p), null),
            default => throw new \InvalidArgumentException("Unknown reference source: {$source}. Use ".implode(', ', self::KEYS).'.'),
        };

        foreach ($generator as $item) {
            yield $item;

            if ($limit !== null && ++$count >= $limit) {
                return;
            }
        }
    }

    /**
     * HWPriceGuide: the sitemap (1 request) lists 171 category pages — the
     * main lines — and ~2,054 group pages, each a whole sub-series with every
     * item's name and image. ~2,230 requests for ~33,000 items.
     */
    private function hwPriceGuide(): iterable
    {
        $base = 'https://hwpriceguide.com';
        $sitemap = $this->http->get("{$base}/sitemap.xml")?->body() ?? '';
        preg_match_all('#<loc>(https?://[^<]+/(category|group)/[^<]+)</loc>#', $sitemap, $m, PREG_SET_ORDER);

        $lines = [];

        foreach ($m as [, $url, $kind]) {
            if ($kind === 'category' && ($html = $this->http->get($url)?->body())) {
                $line = $this->between($html, '<title>', '</title>');
                $line = trim(preg_replace(['/^Hot Wheels\s+/i', '/\s+Price Guide$/i'], '', html_entity_decode($line)));
                $lines[trim((string) parse_url($url, PHP_URL_PATH), '/')] = $line;
            }
        }

        foreach ($m as [, $url, $kind]) {
            if ($kind !== 'group' || ! ($html = $this->http->get($url)?->body())) {
                continue;
            }

            $path = explode('/', trim((string) parse_url($url, PHP_URL_PATH), '/'));
            $title = trim(preg_replace('/\s+Price Guide$/i', '', html_entity_decode($this->between($html, '<title>', '</title>'))));
            $brand = 'Hot Wheels';
            $year = null;
            $series = $title;

            if (preg_match('/^(.*?)\s+((?:19|20)\d\d)\s+(.+)$/', $title, $t)) {
                [$brand, $year, $series] = [$t[1] ?: 'Hot Wheels', (int) $t[2], $t[3]];
            }

            preg_match_all('#<img[^>]+src="(/dist/images/cars/([^"/]+)\.(?:jpe?g|png|webp))"[^>]*alt="([^"]*)"#i', $html, $cards, PREG_SET_ORDER);
            $seen = [];

            foreach ($cards as [, $src, $slug, $alt]) {
                if (isset($seen[$slug])) {
                    continue;
                }

                $seen[$slug] = true;

                yield [
                    'source_key' => $slug,
                    'brand' => $brand,
                    'line' => $lines['category/'.($path[1] ?? '')] ?? null,
                    'series' => $series,
                    'year' => $year,
                    'name' => html_entity_decode($alt, ENT_QUOTES),
                    'item_code' => null,
                    'scale' => '1:64',
                    'image_urls' => [$base.$src],
                    'page_url' => "{$base}/item/{$slug}/",
                ];
            }
        }
    }

    /**
     * TSM's Mini GT site: "Full Collection" (list id 13), 12 products a page,
     * each card with name, item number and image; then the series-type lists
     * only to say which line an item belongs to, and Accessories to leave
     * those out. ~1 request per 12 products.
     */
    private function miniGt(): iterable
    {
        $base = 'https://minigt.tsm-models.com/';
        $lines = [21 => 'Kaido House', 66 => 'Super GT Series', 69 => 'IMSA', 73 => '007 Movie Car',
            70 => 'QubeCarz', 23 => 'Limited Edition', 5 => 'Regional Exclusive Items', 34 => 'Mini GT Set'];
        $lineOf = $cards = [];

        // Some lines (Kaido House, QubeCarz) are not in Full Collection at
        // all, so every list contributes items; an item number appears once.
        foreach ($lines as $listId => $line) {
            foreach ($this->miniGtList($base, $listId) as $card) {
                $lineOf[$card['code']] ??= $line;
                $cards[$card['code'] ?: 'id:'.$card['id']] ??= $card;
            }
        }

        foreach ($this->miniGtList($base, 13) as $card) {
            $cards[$card['code'] ?: 'id:'.$card['id']] ??= $card;
        }

        $accessories = array_column(iterator_to_array($this->miniGtList($base, 11), false), 'code', 'code');

        foreach ($cards as $card) {
            if (isset($accessories[$card['code']])) {
                continue;
            }

            yield [
                'source_key' => $card['code'] !== '' ? $card['code'] : 'id:'.$card['id'],
                'brand' => str_starts_with($card['code'], 'QZ') || str_contains($card['code'], ' - QZ') ? 'QubeCarz' : $this->tsmBrand($card['code']),
                'line' => $lineOf[$card['code']] ?? null,
                'series' => null,
                'year' => null,
                'name' => $card['name'],
                'item_code' => $card['code'] !== '' ? $card['code'] : null,
                'scale' => '1:64',
                'image_urls' => [$base.$card['src']],
                'page_url' => "{$base}index.php?action=product-detail&id={$card['id']}",
            ];
        }
    }

    /**
     * Every card of one Mini GT list, page by page until a page is empty or
     * repeats the last (some lists stop advancing rather than ending).
     *
     * @return iterable<array{id: string, code: string, name: string, src: string}>
     */
    private function miniGtList(string $base, int $listId): iterable
    {
        $previous = null;

        // Pages are numbered from 1 (p=0 is page 1 again).
        for ($page = 1; $page < 500; $page++) {
            $html = (string) $this->http->get("{$base}index.php?action=product-list&b_id={$listId}&p={$page}")?->body();
            preg_match_all('#<img src="(upload/[^"]+)" alt="([^"]*)"\s*/?>.*?product-detail&id=(\d+).*?<p class="m-0">([^<]*)</p>#s', $html, $cards, PREG_SET_ORDER);
            $ids = implode(',', array_column($cards, 3));

            if ($cards === [] || $ids === $previous) {
                return;
            }

            $previous = $ids;

            foreach ($cards as [, $src, $name, $id, $code]) {
                yield ['id' => $id, 'code' => trim(html_entity_decode($code)), 'name' => html_entity_decode(trim($name), ENT_QUOTES), 'src' => $src];
            }
        }
    }

    /**
     * Inno64: WooCommerce's public Store API, 100 products a request
     * (607 products: 7 requests).
     */
    private function inno64(): iterable
    {
        for ($page = 1; $page < 100; $page++) {
            $products = $this->http->get("https://www.inno-models.com/wp-json/wc/store/v1/products?per_page=100&page={$page}")?->json();

            if (! is_array($products) || $products === []) {
                return;
            }

            foreach ($products as $p) {
                $categories = array_values(array_diff(array_map(fn ($c) => html_entity_decode($c['name']), $p['categories'] ?? []), ['Diecast', 'Uncategorized']));

                yield [
                    'source_key' => $p['sku'] ?: 'id:'.$p['id'],
                    'brand' => 'Inno64',
                    'line' => $categories[0] ?? null,
                    'series' => $categories[1] ?? null,
                    'year' => null,
                    'name' => html_entity_decode(strip_tags($p['name']), ENT_QUOTES),
                    'item_code' => $p['sku'] ?: null,
                    'scale' => '1:64',
                    // WordPress's 300 px thumbnails are enough for training.
                    'image_urls' => array_slice(array_values(array_filter(array_map(fn ($i) => $i['thumbnail'] ?? $i['src'] ?? null, $p['images'] ?? []))), 0, 4),
                    'page_url' => $p['permalink'] ?? null,
                ];
            }
        }
    }

    /**
     * A Shopify store's public products.json, 250 products a request.
     */
    private function shopify(string $base, callable $keep, ?string $brand): iterable
    {
        for ($page = 1; $page < 200; $page++) {
            $products = $this->http->get("{$base}/products.json?limit=250&page={$page}")?->json('products');

            if (! is_array($products) || $products === []) {
                return;
            }

            foreach ($products as $p) {
                if (! $keep($p)) {
                    continue;
                }

                $title = trim(preg_replace('/^\[[^\]]*\]\s*/', '', html_entity_decode($p['title'], ENT_QUOTES)));
                $sku = collect($p['variants'] ?? [])->pluck('sku')->filter()->first();
                $scale = preg_match('/\b1:(\d{2,3})\b/', $title, $s) ? "1:{$s[1]}" : null;
                // "VMB 1:64 Porsche 992 GT3 RS Iridescent White VMB-992GT3-6409"
                // → brand "VMB", name "Porsche 992 GT3 RS Iridescent White".
                [$before, $after] = $scale !== null ? array_map('trim', explode($scale, $title, 2)) + [1 => ''] : ['', $title];
                $name = $sku ? trim(str_ireplace($sku, '', $after)) : $after;
                $year = collect($p['tags'] ?? [])->map(fn ($t) => preg_match('/^((?:19|20)\d\d)-\d{1,2}$/', $t, $y) ? (int) $y[1] : null)->filter()->first();

                yield [
                    'source_key' => $p['handle'],
                    'brand' => $brand ?? ($before !== '' ? $before : ($p['vendor'] ?? null)),
                    'line' => null,
                    'series' => null,
                    'year' => $year,
                    'name' => $name !== '' ? $name : $title,
                    'item_code' => $sku,
                    'scale' => $scale,
                    'image_urls' => collect($p['images'] ?? [])->take(4)
                        ->map(fn ($i) => $i['src'].(str_contains($i['src'], '?') ? '&' : '?').'width='.self::IMAGE_WIDTH)->values()->all(),
                    'page_url' => "{$base}/products/{$p['handle']}",
                ];
            }
        }
    }

    /**
     * Brand as the catalog spells it, and the main line it implies — so
     * "TLVN Tomica Limited Vintage Neo" files under Tomica › Tomica Limited
     * Vintage Neo, the line the Tomica import already uses.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function normaliseBrand(?string $brand, ?string $line = null): array
    {
        $b = trim((string) $brand);
        $rules = [
            '/limited vintage neo|^tlvn?\b/i' => ['Tomica', 'Tomica Limited Vintage Neo'],
            '/limited vintage/i' => ['Tomica', 'Tomica Limited Vintage'],
            '/tomica premium/i' => ['Tomica', 'Tomica Premium'],
            '/^tomica/i' => ['Tomica', null],
            '/kaido house/i' => ['Mini GT', 'Kaido House'],
            '/^mini ?gt/i' => ['Mini GT', 'Mini GT'],
            '/^pop ?race/i' => ['Pop Race', null],
            '/^inno ?64/i' => ['Inno64', null],
            '/^para ?64/i' => ['Para64', null],
            '/^tarmac/i' => ['Tarmac Works', null],
            '/^hot ?wheels/i' => ['Hot Wheels', null],
            '/^matchbox/i' => ['Matchbox', null],
        ];

        foreach ($rules as $pattern => [$name, $impliedLine]) {
            if (preg_match($pattern, $b)) {
                return [$name, $line ?? $impliedLine];
            }
        }

        return [$b !== '' ? $b : null, $line];
    }

    private function isAccessory(array $product): bool
    {
        $text = mb_strtolower(implode(' ', [$product['title'] ?? '', implode(' ', $product['tags'] ?? []), $product['product_type'] ?? '']));

        return (bool) preg_match('/diorama|accessor|display case|figure|garage|parking|backdrop|keychain|tool kit|apparel|t-shirt|sticker|gift card/', $text);
    }

    private function tsmBrand(string $code): string
    {
        return match (true) {
            str_starts_with($code, 'KHMG') => 'Kaido House x Mini GT',
            str_starts_with($code, 'MGT') => 'Mini GT',
            str_starts_with($code, 'TS') => 'TopSpeed',
            default => 'Mini GT',
        };
    }

    private function between(string $text, string $start, string $end): string
    {
        $i = strpos($text, $start);

        if ($i === false) {
            return '';
        }

        $i += strlen($start);
        $j = strpos($text, $end, $i);

        return trim(substr($text, $i, $j === false ? null : $j - $i));
    }
}
