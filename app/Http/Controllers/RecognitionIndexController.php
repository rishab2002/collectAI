<?php

namespace App\Http\Controllers;

use App\Services\Recognition\RecognitionIndex;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The recognition index, for a recognizer that can't share the app's folder.
 *
 * Under docker compose the recognizer reads catalog-index.json off a shared
 * volume and this route is never used. On Railway each service has its own
 * disk, so the recognizer polls here instead (RECOGNIZER_INDEX_URL), over the
 * private network, with the shared RECOGNIZER_INDEX_TOKEN.
 *
 * Answers 404 — not 401 or 403 — unless the token is configured and matches,
 * so the route can't be told apart from one that doesn't exist (the same rule
 * as the admin area). The index holds live catalog records only, which the
 * public pages show anyway; the token keeps a multi-megabyte JSON build from
 * being something anyone can make the server do on demand.
 *
 * Written on first request if missing; afterwards the scheduler's nightly
 * export refreshes it. An ETag lets the recognizer's poll cost a 304.
 */
class RecognitionIndexController extends Controller
{
    public function __invoke(Request $request, RecognitionIndex $index): BinaryFileResponse
    {
        $token = config('catalog.recognition.index_token');

        if (! is_string($token) || $token === '' || ! hash_equals('Bearer '.$token, (string) $request->header('Authorization'))) {
            throw new NotFoundHttpException;
        }

        $path = config('catalog.recognition.index_path');

        if (! is_file($path)) {
            $index->write($path);
        }

        $response = new BinaryFileResponse($path, 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'private, no-cache',
        ], public: false, autoEtag: true, autoLastModified: true);

        $response->isNotModified($request);

        return $response;
    }
}
