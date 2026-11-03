<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Casting;
use App\Models\CastingVersion;
use App\Models\PriceCheck;
use App\Models\PriceHistory;
use App\Models\Release;
use App\Models\Series;
use App\Models\Variation;
use App\Services\Prices\PriceTracker;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Monthly prices: eBay sold (Marketplace Insights), then eBay asking (Browse),
 * then MSRP — through eBay's official APIs only, faked here at the HTTP layer.
 */
class PriceTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'catalog.prices.ebay.client_id' => 'id',
            'catalog.prices.ebay.client_secret' => 'secret',
            'catalog.prices.ebay.insights' => true,
            'catalog.prices.ebay.listings_fallback' => true,
            'catalog.prices.daily_budget' => 1000,
            'catalog.prices.sample' => 5,
            'catalog_prices' => [],
        ]);
    }

    private function variation(array $attributes = [], string $casting = 'Bone Shaker', string $line = 'Mainline'): Variation
    {
        $brand = Brand::firstOrCreate(['name' => 'Hot Wheels']);
        $lineRow = Series::factory()->for($brand)->create(['name' => $line]);
        $release = Release::factory()
            ->for(CastingVersion::factory()->for(Casting::factory()->state(['name' => $casting])), 'castingVersion')
            ->for(Series::factory()->for($brand)->state(['name' => 'HW Art Cars', 'parent_id' => $lineRow->id]))
            ->create(['year' => 2024]);

        return Variation::factory()->for($release)->create(['color' => 'Black', 'toy_number' => 'HTB22', ...$attributes]);
    }

    /**
     * @param  list<array<string, mixed>>  $sales
     * @param  list<array<string, mixed>>  $listings
     */
    private function fakeEbay(array $sales = [], array $listings = []): void
    {
        Http::fake([
            'api.ebay.com/identity/*' => Http::response(['access_token' => 'token', 'expires_in' => 7200]),
            'api.ebay.com/buy/marketplace_insights/*' => Http::response(['itemSales' => $sales]),
            'api.ebay.com/buy/browse/*' => Http::response(['itemSummaries' => $listings]),
        ]);
    }

    private function sale(float $price, string $date, string $title = 'Hot Wheels 2024 Bone Shaker HW Art Cars', string $currency = 'USD'): array
    {
        return ['title' => $title, 'lastSoldPrice' => ['value' => (string) $price, 'currency' => $currency], 'lastSoldDate' => $date];
    }

    public function test_the_sold_price_is_the_median_of_the_five_latest_real_sales(): void
    {
        $variation = $this->variation();
        $this->fakeEbay(sales: [
            $this->sale(3.00, '2026-10-01T00:00:00Z'),
            $this->sale(9.00, '2026-10-05T00:00:00Z'),
            $this->sale(4.00, '2026-10-04T00:00:00Z'),
            $this->sale(5.00, '2026-10-03T00:00:00Z'),
            $this->sale(6.00, '2026-10-02T00:00:00Z'),
            $this->sale(1.00, '2026-09-01T00:00:00Z'), // older than the latest five
            $this->sale(40.00, '2026-10-06T00:00:00Z', 'Hot Wheels lot of 20 incl Bone Shaker'), // a lot
            $this->sale(7.00, '2026-10-06T00:00:00Z', currency: 'GBP'), // other currency
            $this->sale(2.00, '2026-10-06T00:00:00Z', 'Hot Wheels Twin Mill 2024'), // another car
        ]);

        $summary = app(PriceTracker::class)->run();

        $price = PriceHistory::sole();
        $this->assertSame($variation->id, $price->variation_id);
        // Latest five eligible: 9, 4, 5, 6, 3 → median 5.
        $this->assertSame('5.00', $price->price);
        $this->assertSame(PriceHistory::KIND_SOLD, $price->kind);
        $this->assertSame(5, $price->sample_size);
        $this->assertStringContainsString('LH_Sold=1', $price->url);
        $this->assertSame('eBay sold · median of last 5 sales', $price->label());
        $this->assertSame('sold', PriceCheck::sole()->outcome);
        $this->assertSame(1, $summary->count('priced.sold'));

        // The toy number is the first thing searched for.
        Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'q=Hot Wheels Bone Shaker HTB22'));
    }

    public function test_asking_prices_are_used_only_when_nothing_sold_and_are_labelled_so(): void
    {
        $this->variation();
        $this->fakeEbay(listings: [
            ['title' => 'Hot Wheels Bone Shaker 2024 black', 'price' => ['value' => '6.00', 'currency' => 'USD']],
            ['title' => 'Hot Wheels Bone Shaker HW Art Cars', 'price' => ['value' => '8.00', 'currency' => 'USD']],
        ]);

        app(PriceTracker::class)->run();

        $price = PriceHistory::sole();
        $this->assertSame(PriceHistory::KIND_LISTING, $price->kind);
        $this->assertSame('7.00', $price->price);
        $this->assertSame('eBay asking · median of 2 listings', $price->label());
    }

    public function test_msrp_is_the_last_resort_and_is_not_repeated_when_unchanged(): void
    {
        $variation = $this->variation();
        $this->fakeEbay();
        config(['catalog_prices' => ['Hot Wheels' => ['Mainline' => ['price' => 1.25, 'currency' => 'USD']]]]);

        app(PriceTracker::class)->run();

        $price = PriceHistory::sole();
        $this->assertSame(PriceHistory::KIND_MSRP, $price->kind);
        $this->assertSame('1.25', $price->price);
        $this->assertSame('MSRP (list price)', $price->label());

        // A month later, same MSRP: checked again, but no duplicate row.
        $this->travel(31)->days();
        app(PriceTracker::class)->run();
        $this->assertSame(1, PriceHistory::count());
        $this->assertTrue(PriceCheck::sole()->checked_at->greaterThan(now()->subDay()), 'The check itself is still recorded.');
    }

    public function test_without_ebay_keys_only_msrp_is_tried_and_the_run_says_so(): void
    {
        config(['catalog.prices.ebay.client_id' => null]);
        Http::fake();
        $this->variation();

        $summary = app(PriceTracker::class)->run();

        Http::assertNothingSent();
        $this->assertStringContainsString('eBay is not set up', $summary->problems()[0]);
        $this->assertSame('none', PriceCheck::sole()->outcome);
    }

    public function test_a_car_with_no_price_anywhere_is_not_retried_until_its_month_is_up(): void
    {
        $this->variation();
        $this->fakeEbay();

        app(PriceTracker::class)->run();
        $this->assertSame('none', PriceCheck::sole()->outcome);
        $this->assertCount(0, app(PriceTracker::class)->due(100));

        $this->travel(31)->days();
        $this->assertCount(1, app(PriceTracker::class)->due(100));
    }

    public function test_never_checked_cars_go_first_and_unpublished_ones_never(): void
    {
        $old = $this->variation();
        PriceCheck::create(['variation_id' => $old->id, 'checked_at' => now()->subDays(40), 'outcome' => 'none']);
        $fresh = $this->variation(['color' => 'Red']);
        $this->variation(['color' => 'Blue'])->forceFill(['status' => 'pending'])->save();

        $due = app(PriceTracker::class)->due(10)->pluck('id')->all();

        $this->assertSame([$fresh->id, $old->id], $due);
    }

    public function test_the_daily_budget_caps_the_api_calls(): void
    {
        config(['catalog.prices.daily_budget' => 3]);
        foreach (['Red', 'Blue', 'Green'] as $colour) {
            $this->variation(['color' => $colour]);
        }
        $this->fakeEbay();

        $summary = app(PriceTracker::class)->run();

        // The budget is checked before each car, so a run can finish the car it
        // started: the first car here costs 2 tokens + 4 searches = 6 calls, and
        // no second car is begun.
        $this->assertSame(6, $summary->count('ebay_calls'));
        $this->assertSame(1, $summary->count('stopped_at_budget'));
        $this->assertSame(1, PriceCheck::count());
    }

    public function test_a_rejected_credential_stops_the_run(): void
    {
        $this->variation();
        Http::fake(['api.ebay.com/identity/*' => Http::response(['error' => 'invalid_client'], 401)]);

        $this->expectException(RequestException::class);

        app(PriceTracker::class)->run();
    }

    public function test_the_price_run_is_scheduled_daily(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'catalog:update-prices'));

        $this->assertNotNull($event);
        $this->assertSame('17 3 * * *', $event->expression);
    }

    public function test_the_car_page_shows_the_price_with_what_kind_it_is(): void
    {
        $variation = $this->variation();
        PriceHistory::factory()->for($variation)->create([
            'price' => 5, 'kind' => 'sold', 'sample_size' => 5, 'observed_at' => now(), 'url' => 'https://www.ebay.com/sch/i.html?_nkw=x',
        ]);

        $this->get(route('catalog.casting', $variation->release->castingVersion->casting_id))
            ->assertOk()
            ->assertSee('$5.00', false)
            ->assertSee('eBay sold · median of last 5 sales')
            ->assertSee('see listings');
    }
}
