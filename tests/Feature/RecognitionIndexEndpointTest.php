<?php

namespace Tests\Feature;

use App\Services\Recognition\RecognitionIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /internal/recognition-index — how a recognizer on another machine
 * (Railway, where services don't share a disk) gets the catalog index.
 */
class RecognitionIndexEndpointTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/collectai-index-endpoint-'.bin2hex(random_bytes(4)).'/catalog-index.json';
        config(['catalog.recognition.index_path' => $this->path]);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @rmdir(dirname($this->path));

        parent::tearDown();
    }

    public function test_it_does_not_exist_until_a_token_is_configured(): void
    {
        config(['catalog.recognition.index_token' => null]);

        $this->get('/internal/recognition-index', ['Authorization' => 'Bearer '])->assertNotFound();
        $this->get('/internal/recognition-index')->assertNotFound();
        $this->assertFileDoesNotExist($this->path);
    }

    public function test_a_wrong_or_missing_token_is_a_404_not_a_401(): void
    {
        config(['catalog.recognition.index_token' => 'right-token-0123456789abcdef']);

        $this->get('/internal/recognition-index')->assertNotFound();
        $this->get('/internal/recognition-index', ['Authorization' => 'Bearer wrong'])->assertNotFound();
        $this->get('/internal/recognition-index', ['Authorization' => 'right-token-0123456789abcdef'])->assertNotFound();
        $this->assertFileDoesNotExist($this->path, 'a refused request must not make the server build the index');
    }

    public function test_the_right_token_gets_the_index_written_on_first_request(): void
    {
        config(['catalog.recognition.index_token' => 'right-token-0123456789abcdef']);

        $response = $this->get('/internal/recognition-index', ['Authorization' => 'Bearer right-token-0123456789abcdef']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');
        $this->assertFileExists($this->path);
        $this->assertSame(RecognitionIndex::FORMAT, json_decode(file_get_contents($this->path), true)['format']);
        $this->assertNotNull($response->headers->get('ETag'));
        // Sessionless: a poll every few minutes must not leave a session row each time.
        $this->assertSame([], array_map(fn ($c) => $c->getName(), $response->headers->getCookies()));
    }

    public function test_an_unchanged_index_costs_a_304(): void
    {
        config(['catalog.recognition.index_token' => 'right-token-0123456789abcdef']);
        $auth = ['Authorization' => 'Bearer right-token-0123456789abcdef'];

        $etag = $this->get('/internal/recognition-index', $auth)->headers->get('ETag');

        $this->get('/internal/recognition-index', $auth + ['If-None-Match' => $etag])->assertStatus(304);
    }

    public function test_it_serves_the_file_the_nightly_export_wrote_rather_than_rebuilding(): void
    {
        config(['catalog.recognition.index_token' => 'right-token-0123456789abcdef']);
        mkdir(dirname($this->path), 0775, true);
        file_put_contents($this->path, '{"format":"exported-earlier"}');

        $response = $this->get('/internal/recognition-index', ['Authorization' => 'Bearer right-token-0123456789abcdef']);

        $response->assertOk();
        $this->assertSame('{"format":"exported-earlier"}', $response->streamedContent());
    }
}
