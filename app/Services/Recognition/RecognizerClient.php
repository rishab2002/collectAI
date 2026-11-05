<?php

namespace App\Services\Recognition;

use App\Exceptions\RecognizerUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * The website's side of the recognizer service — a process on our own box,
 * not an outside API. Every failure becomes one exception with a message a
 * person can act on, because "cURL error 7" helps nobody holding a phone.
 */
final class RecognizerClient
{
    /**
     * @return array<string, mixed>
     */
    public function recognize(string $image, string $layout = 'auto'): array
    {
        return $this->post('/recognize', $image, ['layout' => $layout]);
    }

    /**
     * A carded car, read into the OCR schema (app/AI/schemas/ocr_output.json).
     *
     * @return array<string, mixed>
     */
    public function readCard(string $image): array
    {
        return $this->post('/read-card', $image);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function health(): ?array
    {
        try {
            return Http::timeout(5)->get($this->url('/health'))->json();
        } catch (ConnectionException) {
            return null;
        }
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function post(string $path, string $image, array $query = []): array
    {
        $query += [
            'min_confidence' => (string) config('catalog.recognition.min_confidence'),
            'min_margin' => (string) config('catalog.recognition.min_margin'),
        ];

        $base = (string) config('catalog.recognition.url');

        // A misconfigured address is a configuration problem, not a request
        // that failed, and it arrives here looking like neither: an unset
        // Railway variable reference leaves `http://:8080`, and Guzzle throws
        // MalformedUriException from inside the client — an exception this
        // class does not catch, so it escaped as a 500 on the live site
        // (measured 2026-11-05) rather than as the message a person can act on
        // that every other failure here becomes.
        // Not blank(): parse_url() returns *false* for a URL this malformed,
        // and blank(false) is false, so the guard would wave it straight
        // through to the exception it exists to prevent.
        $host = parse_url($base, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            throw new RecognizerUnavailableException(sprintf(
                'The recognizer address is not configured: RECOGNIZER_URL is "%s", which has no host. '
                .'On Railway it should be http://${{<the recognizer service\'s name>.RAILWAY_PRIVATE_DOMAIN}}:8080 '
                .'— an empty host means that reference names a service that does not exist.',
                $base,
            ));
        }

        try {
            $response = Http::timeout(config('catalog.recognition.timeout'))
                ->withBody($image, 'application/octet-stream')
                ->post($this->url($path).'?'.http_build_query($query))
                ->throw();
        } catch (ConnectionException) {
            throw new RecognizerUnavailableException(
                'The recognizer isn’t running. Start it with `docker compose up -d recognizer` (see README, “Scan”).'
            );
        } catch (RequestException $e) {
            throw new RecognizerUnavailableException(
                'The recognizer could not read that photo: '.($e->response->json('error') ?? 'HTTP '.$e->response->status()).'.'
            );
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new RecognizerUnavailableException('The recognizer answered with something that isn’t JSON.');
        }

        return $json;
    }

    private function url(string $path): string
    {
        return config('catalog.recognition.url').$path;
    }
}
