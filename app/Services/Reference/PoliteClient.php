<?php

namespace App\Services\Reference;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * HTTP for the reference sources: an identifying user agent, at most
 * `perSecond` requests to a host, and backing off when asked (429/503).
 * Every source is read through its bulk listing (Shopify's products.json,
 * WooCommerce's Store API, list and group pages) so a whole catalog is a few
 * hundred requests, not one per product.
 */
class PoliteClient
{
    public const USER_AGENT = 'collectAI-training/1.0 (+https://collectai.club; training under written permission)';

    /** @var array<string, float> host => earliest next request */
    private array $next = [];

    public int $requests = 0;

    public function __construct(private readonly float $perSecond = 2.0, private readonly bool $sleep = true) {}

    public function get(string $url): ?Response
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $wait = ($this->next[$host] ?? 0) - microtime(true);

            if ($wait > 0 && $this->sleep) {
                usleep((int) ($wait * 1_000_000));
            }

            $this->next[$host] = microtime(true) + 1 / $this->perSecond;
            $this->requests++;
            try {
                $response = Http::withUserAgent(self::USER_AGENT)->timeout(30)->get($url);
            } catch (ConnectionException) {
                // A dropped connection is retried like a busy server, never fatal:
                // one bad page shouldn't end a run of thousands.
                if ($this->sleep) {
                    sleep(2 ** $attempt);
                }

                continue;
            }

            if (in_array($response->status(), [429, 503], true)) {
                if ($this->sleep) {
                    sleep(5 * 2 ** $attempt);
                }

                continue;
            }

            return $response->successful() ? $response : null;
        }

        return null;
    }
}
