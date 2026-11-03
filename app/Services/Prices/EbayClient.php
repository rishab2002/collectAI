<?php

namespace App\Services\Prices;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * eBay's official REST APIs, with an application token.
 *
 * Only the APIs: eBay's User Agreement forbids scraping its pages, and the
 * sold-price data comes from Marketplace Insights, which eBay grants on
 * application. The Browse API (active listings) is open to every developer
 * account.
 */
final class EbayClient
{
    private const SCOPE_BASE = 'https://api.ebay.com/oauth/api_scope';

    private const SCOPE_INSIGHTS = 'https://api.ebay.com/oauth/api_scope/buy.marketplace.insights';

    /** API calls made by this instance — the daily budget counts them. */
    public int $calls = 0;

    public function configured(): bool
    {
        return filled(config('catalog.prices.ebay.client_id')) && filled(config('catalog.prices.ebay.client_secret'));
    }

    /**
     * Recently sold items matching the query, newest first.
     *
     * @return list<array{title: string, price: float, currency: string, sold_at: string|null}>
     */
    public function sold(string $query, int $limit = 20): array
    {
        $response = $this->get('/buy/marketplace_insights/v1_beta/item_sales/search', $query, $limit, insights: true);

        $sales = array_map(fn (array $sale): array => [
            'title' => (string) ($sale['title'] ?? ''),
            'price' => (float) data_get($sale, 'lastSoldPrice.value', 0),
            'currency' => (string) data_get($sale, 'lastSoldPrice.currency', ''),
            'sold_at' => $sale['lastSoldDate'] ?? null,
        ], $response['itemSales'] ?? []);

        usort($sales, fn (array $a, array $b): int => strcmp((string) $b['sold_at'], (string) $a['sold_at']));

        return $sales;
    }

    /**
     * Fixed-price listings currently for sale.
     *
     * @return list<array{title: string, price: float, currency: string}>
     */
    public function listings(string $query, int $limit = 20): array
    {
        $response = $this->get('/buy/browse/v1/item_summary/search', $query, $limit, insights: false, filter: 'buyingOptions:{FIXED_PRICE}');

        return array_map(fn (array $item): array => [
            'title' => (string) ($item['title'] ?? ''),
            'price' => (float) data_get($item, 'price.value', 0),
            'currency' => (string) data_get($item, 'price.currency', ''),
        ], $response['itemSummaries'] ?? []);
    }

    /**
     * A link a person can follow to see the evidence on eBay itself.
     */
    public static function searchUrl(string $query, bool $sold): string
    {
        $params = ['_nkw' => $query, '_sacat' => config('catalog.prices.ebay.category_id')];

        if ($sold) {
            $params += ['LH_Sold' => 1, 'LH_Complete' => 1];
        }

        return 'https://www.ebay.com/sch/i.html?'.http_build_query($params);
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path, string $query, int $limit, bool $insights, ?string $filter = null): array
    {
        $this->calls++;

        $response = $this->http($this->token($insights))->get(rtrim(config('catalog.prices.ebay.api'), '/').$path, array_filter([
            'q' => $query,
            'category_ids' => config('catalog.prices.ebay.category_id'),
            'limit' => $limit,
            'filter' => $filter,
        ]));

        // No matches is a normal answer, not an error.
        if ($response->status() === 204) {
            return [];
        }

        return $response->throw()->json() ?? [];
    }

    /**
     * An application token (client-credentials grant), cached until shortly
     * before it expires — two hours on eBay — so a run asks for one, not one
     * per car.
     */
    private function token(bool $insights): string
    {
        if (! $this->configured()) {
            throw new RuntimeException('eBay is not configured: set EBAY_CLIENT_ID and EBAY_CLIENT_SECRET.');
        }

        $scope = $insights ? self::SCOPE_INSIGHTS : self::SCOPE_BASE;

        return Cache::remember('ebay-token:'.md5($scope.config('catalog.prices.ebay.client_id')), now()->addMinutes(100), function () use ($scope): string {
            $this->calls++;

            $response = Http::asForm()
                ->withBasicAuth(config('catalog.prices.ebay.client_id'), config('catalog.prices.ebay.client_secret'))
                ->timeout(30)
                ->post(rtrim(config('catalog.prices.ebay.api'), '/').'/identity/v1/oauth2/token', [
                    'grant_type' => 'client_credentials',
                    'scope' => $scope,
                ])
                ->throw();

            return (string) $response->json('access_token');
        });
    }

    private function http(string $token): PendingRequest
    {
        return Http::withToken($token)
            ->withHeaders(['X-EBAY-C-MARKETPLACE-ID' => config('catalog.prices.ebay.marketplace')])
            ->acceptJson()
            ->retry(2, 1000, throw: false)
            ->timeout(30);
    }
}
