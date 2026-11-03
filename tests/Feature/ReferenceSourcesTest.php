<?php

namespace Tests\Feature;

use App\Models\ReferenceItem;
use App\Services\Reference\PoliteClient;
use App\Services\Reference\ReferenceSources;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The permitted reference sources, read through their bulk listings — faked
 * here with the shapes measured on the real sites (2026-11-04).
 */
class ReferenceSourcesTest extends TestCase
{
    use RefreshDatabase;

    private function read(string $source): array
    {
        return iterator_to_array((new ReferenceSources(new PoliteClient(1000, sleep: false)))->items($source), false);
    }

    public function test_para64_from_shopify_keeps_only_para64_products(): void
    {
        Http::fake([
            'paragonmodelstore.com/products.json?limit=250&page=1' => Http::response(['products' => [
                ['title' => '1:64 1999 Honda Civic Coupe EM1 Yellow', 'handle' => 'civic-em1', 'vendor' => 'PARA64', 'product_type' => 'Scale Models',
                    'tags' => ['2026-3', 'Honda'], 'variants' => [['sku' => 'PA-55627'], ['sku' => 'PA-65627']],
                    'images' => [['src' => 'https://cdn.shopify.com/a.jpg?v=1'], ['src' => 'https://cdn.shopify.com/b.jpg?v=2']]],
                ['title' => '1:18 Something Else', 'handle' => 'other', 'vendor' => 'Paragon Models', 'tags' => [], 'variants' => [], 'images' => []],
            ]]),
            'paragonmodelstore.com/products.json?limit=250&page=2' => Http::response(['products' => []]),
        ]);

        $items = $this->read('para64');

        $this->assertCount(1, $items);
        $this->assertSame(['civic-em1', 'Para64', 2026, '1999 Honda Civic Coupe EM1 Yellow', 'PA-55627', '1:64'],
            [$items[0]['source_key'], $items[0]['brand'], $items[0]['year'], $items[0]['name'], $items[0]['item_code'], $items[0]['scale']]);
        $this->assertSame('https://cdn.shopify.com/a.jpg?v=1&width=448', $items[0]['image_urls'][0], 'the CDN sends a small copy');
    }

    public function test_horizon_reads_the_brand_from_the_title_and_skips_accessories(): void
    {
        Http::fake([
            'horizondiecast.com/products.json?limit=250&page=1' => Http::response(['products' => [
                ['title' => '[Preorder] VMB 1:64 Porsche 992 GT3 RS Iridescent White VMB-992GT3-6409', 'handle' => 'vmb-992', 'vendor' => 'Horizon Diecast',
                    'tags' => ['1:64'], 'variants' => [['sku' => 'VMB-992GT3-6409']], 'images' => [['src' => 'https://cdn.shopify.com/v.jpg']]],
                ['title' => 'MoreArt 1:64 Street Diorama', 'handle' => 'diorama', 'vendor' => 'Horizon Diecast',
                    'tags' => ['Diorama & Accessories'], 'variants' => [], 'images' => []],
            ]]),
            'horizondiecast.com/products.json?limit=250&page=2' => Http::response(['products' => []]),
        ]);

        $items = $this->read('horizon');

        $this->assertCount(1, $items);
        $this->assertSame(['VMB', 'Porsche 992 GT3 RS Iridescent White', 'VMB-992GT3-6409'], [$items[0]['brand'], $items[0]['name'], $items[0]['item_code']]);
    }

    public function test_inno64_from_the_woocommerce_store_api(): void
    {
        Http::fake([
            'www.inno-models.com/wp-json/wc/store/v1/products?per_page=100&page=1' => Http::response([[
                'id' => 7491, 'name' => 'Honda Accord JTCC &#8211; Mugen Test Car 1996', 'sku' => 'IN64-CD6-WH96', 'permalink' => 'https://www.inno-models.com/product/x/',
                'categories' => [['name' => 'Diecast'], ['name' => 'Limited Editions']],
                'images' => [['src' => 'https://www.inno-models.com/a.jpg', 'thumbnail' => 'https://www.inno-models.com/a-300x171.jpg']],
            ]]),
            'www.inno-models.com/wp-json/wc/store/v1/products?per_page=100&page=2' => Http::response([]),
        ]);

        $item = $this->read('inno64')[0];

        $this->assertSame(['IN64-CD6-WH96', 'Inno64', 'Limited Editions', 'Honda Accord JTCC – Mugen Test Car 1996'], [$item['source_key'], $item['brand'], $item['line'], $item['name']]);
        $this->assertSame(['https://www.inno-models.com/a-300x171.jpg'], $item['image_urls']);
    }

    public function test_mini_gt_walks_lists_from_page_one_and_files_lines(): void
    {
        $card = fn (int $id, string $code, string $name) => "<div><img src=\"upload/picfile/{$id}.JPEG\" alt=\"{$name}\"/> <a href=\"index.php?action=product-detail&id={$id}\"></a></div>"
            ."<a href=\"index.php?action=product-detail&id={$id}&b_id=1\">{$name}</a> <p class=\"m-0\">{$code}</p>";
        $page = fn (array $cards) => '<div class="pd-list">'.implode('', $cards).'</div>';

        Http::fake(function ($request) use ($card, $page) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return Http::response(match ([(int) $q['b_id'], (int) $q['p']]) {
                [21, 1] => $page([$card(2, 'KHMG322', 'Honda NSX Kaido House')]),
                [13, 1] => $page([$card(1, 'MGT00001', 'Lamborghini Huracán'), $card(9, 'MGT00999', 'Display Case')]),
                [13, 2] => $page([$card(1, 'MGT00001', 'Lamborghini Huracán'), $card(9, 'MGT00999', 'Display Case')]), // repeats: the end
                [11, 1] => $page([$card(9, 'MGT00999', 'Display Case')]),
                default => $page([]),
            });
        });

        $items = collect($this->read('tsm'))->keyBy('item_code');

        $this->assertSame(['KHMG322', 'MGT00001'], $items->keys()->sort()->values()->all(), 'Kaido House comes from its own list; accessories are left out');
        $this->assertSame('Kaido House', $items['KHMG322']['line']);
        $this->assertSame('https://minigt.tsm-models.com/upload/picfile/1.JPEG', $items['MGT00001']['image_urls'][0]);
    }

    public function test_hwpriceguide_reads_whole_series_from_group_pages(): void
    {
        Http::fake([
            'hwpriceguide.com/sitemap.xml' => Http::response('<urlset><url><loc>https://hwpriceguide.com/category/car-culture/</loc></url>'
                .'<url><loc>https://hwpriceguide.com/group/car-culture/2022-autostrasse/</loc></url>'
                .'<url><loc>https://hwpriceguide.com/item/car-culture-autostrasse-bmw-m3-e46/</loc></url></urlset>'),
            'hwpriceguide.com/category/car-culture/' => Http::response('<title>Hot Wheels Car Culture Price Guide</title>'),
            'hwpriceguide.com/group/car-culture/2022-autostrasse/' => Http::response('<title>Hot Wheels 2022 AutoStrasse Price Guide</title>'
                .'<img class="a" src="/dist/images/cars/car-culture-autostrasse-bmw-m3-e46.jpg" alt="BMW M3 (E46)">'
                .'<img class="b" src="/dist/images/cars/car-culture-autostrasse-bmw-m3-e46.jpg" alt="BMW M3 (E46)">'
                .'<img class="a" src="/dist/images/cars/car-culture-autostrasse-73-volvo-142-gl.jpg" alt="&#039;73 Volvo 142 GL">'),
        ]);

        $items = $this->read('hwpriceguide');

        $this->assertCount(2, $items, 'the same image twice on a page is one item');
        $this->assertSame(['Hot Wheels', 'Car Culture', 'AutoStrasse', 2022, "'73 Volvo 142 GL"],
            [$items[1]['brand'], $items[1]['line'], $items[1]['series'], $items[1]['year'], $items[1]['name']]);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/item/'), 'item pages are never fetched');
    }

    public function test_brands_are_normalised_to_the_catalogs_names(): void
    {
        $this->assertSame(['Tomica', 'Tomica Limited Vintage Neo'], ReferenceSources::normaliseBrand('TLVN Tomica Limited Vintage Neo'));
        $this->assertSame(['Mini GT', 'Kaido House'], ReferenceSources::normaliseBrand('Kaido House x Mini GT'));
        $this->assertSame(['Pop Race', null], ReferenceSources::normaliseBrand('POPRACE'));
        $this->assertSame(['Ignition Model', null], ReferenceSources::normaliseBrand('Ignition Model'));
    }

    public function test_the_command_stores_and_updates_products(): void
    {
        Http::fake([
            'www.inno-models.com/*page=1' => Http::response([['id' => 1, 'name' => 'Nissan R34', 'sku' => 'IN64-R34', 'categories' => [], 'images' => []]]),
            'www.inno-models.com/*' => Http::response([]),
        ]);

        $this->artisan('catalog:import-reference', ['source' => ['inno64'], '--rate' => 1000])->expectsOutputToContain('Inno64: 1 products')->assertSuccessful();
        $this->artisan('catalog:import-reference', ['source' => ['inno64'], '--rate' => 1000])->assertSuccessful();

        $this->assertSame(1, ReferenceItem::count());
        $this->assertSame('inno64|item:IN64-R34', ReferenceItem::sole()->castingKey());
    }
}
