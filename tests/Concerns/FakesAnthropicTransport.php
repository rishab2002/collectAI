<?php

namespace Tests\Concerns;

use Anthropic\Client;
use Anthropic\RequestOptions;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/**
 * Builds an SDK client that talks to canned responses instead of the network,
 * recording each outgoing request.
 *
 * Faking at the transport layer rather than mocking the client is the same call
 * OcrAgentTest makes and for the same reason: the request body is built by the
 * SDK from named arguments, so the parameter names are only proven correct if
 * something actually serializes them. A mocked client asserts that the agent
 * called the method it was written to call, which is true by construction.
 */
trait FakesAnthropicTransport
{
    /** @var list<array{request: Request, response: mixed}> */
    protected array $sent = [];

    /**
     * @param  list<array<string, mixed>>  $responses  One per expected call, in order.
     */
    protected function clientReturning(array $responses): Client
    {
        $stack = HandlerStack::create(new MockHandler(array_map(
            static fn (array $body): Response => new Response(
                200,
                ['content-type' => 'application/json'],
                json_encode($body),
            ),
            $responses,
        )));

        $stack->push(Middleware::history($this->sent));

        return new Client(
            apiKey: 'test-key',
            requestOptions: RequestOptions::with(transporter: new Guzzle(['handler' => $stack])),
        );
    }

    /**
     * A client whose first call comes back as an API error.
     *
     * Driven through a real HTTP status rather than by throwing from a mock, so
     * the SDK does its own status-to-exception mapping — which is the thing the
     * calling code catches on, and the thing that would change under an SDK
     * upgrade.
     */
    protected function clientFailingWith(int $status, string $type = 'invalid_request_error'): Client
    {
        $stack = HandlerStack::create(new MockHandler([
            new Response($status, ['content-type' => 'application/json'], json_encode([
                'type' => 'error',
                'error' => ['type' => $type, 'message' => 'x-api-key header is required'],
            ])),
        ]));

        $stack->push(Middleware::history($this->sent));

        return new Client(
            apiKey: 'test-key',
            requestOptions: RequestOptions::with(
                transporter: new Guzzle(['handler' => $stack]),
                // Otherwise the SDK retries the retryable statuses and drains
                // the queue, and the test fails on an empty MockHandler rather
                // than on the thing it is asserting.
                maxRetries: 0,
            ),
        );
    }

    /**
     * The decoded JSON body of a request the SDK actually sent.
     *
     * @return array<string, mixed>
     */
    protected function sentBody(int $index = 0): array
    {
        return json_decode((string) $this->sent[$index]['request']->getBody(), true);
    }

    /**
     * Wraps a structured-output payload in the message envelope the API returns.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function messageWrapping(array $payload, string $stopReason = 'end_turn'): array
    {
        return [
            'id' => 'msg_01',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-opus-5',
            'content' => [['type' => 'text', 'text' => json_encode($payload)]],
            'stop_reason' => $stopReason,
            'stop_sequence' => null,
            'usage' => ['input_tokens' => 900, 'output_tokens' => 400],
        ];
    }
}
