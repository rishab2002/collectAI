<?php

namespace App\Services\AI;

use App\Exceptions\OcrFailedException;
use App\Models\Submission;
use App\Models\SubmissionPhoto;
use Anthropic\Client;
use Illuminate\Support\Facades\Storage;

/**
 * Reads the packaging text off a capture's photos.
 *
 * Step 2 of the pipeline in CLAUDE.md. Its only job is transcription: what does
 * the card say. It does not normalize names, resolve them against the catalog,
 * or decide whether the release already exists — those are the Metadata,
 * Comparison and Insertion agents, and folding any of them in here would mean a
 * misread and a mismatch become the same failure with the same error message.
 *
 * Lives behind a service class rather than in the controller so Claude API usage
 * stays auditable and swappable, per CLAUDE.md.
 */
class OcrAgent
{
    /**
     * The output fields, in the order the schema lists them. Kept next to the
     * code that reads the response so a schema edit that isn't mirrored here
     * fails a test rather than silently dropping a field.
     *
     * @var list<string>
     */
    public const FIELDS = [
        'brand',
        'year',
        'series',
        'casting_name',
        'collector_number',
        'toy_number',
    ];

    public const SCHEMA_VERSION = 1;

    /**
     * Gates the scalar `fallbacks: "default"` form. The array form
     * (`[['model' => ...]]`) is gated by a *different*, earlier-dated header —
     * pairing either header with the other form is a 400, so these two do not
     * get "updated" to look consistent.
     */
    public const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    /**
     * Vision accepts these four; the capture screen also accepts HEIC/HEIF,
     * which it cannot read. See readablePhotos().
     */
    private const MEDIA_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
    ];

    /**
     * Card photos first: they carry the printed text, and when the photo budget
     * forces a choice they are worth more than another angle of the car. Loose
     * shots still earn their place — a Hot Wheels base is printed with the
     * casting name, year and toy number, so a carded-free capture is readable.
     */
    private const SLOT_PRIORITY = [
        SubmissionPhoto::TYPE_CARD_FRONT,
        SubmissionPhoto::TYPE_CARD_BACK,
        SubmissionPhoto::TYPE_LOOSE,
    ];

    private const SYSTEM_PROMPT = <<<'PROMPT'
        You read photographs of die-cast model car packaging and report the text printed on it.

        Report what is printed, not what it means. Keep the original spelling, capitalisation, abbreviations and punctuation, including anything that looks like a mistake. A later step reconciles these strings against a catalogue, and it can only do that if it sees what the card actually says — an expansion or a spelling correction made here is indistinguishable from the card having said that.

        Use null for any field you cannot read from the photographs. Null is a useful answer. A plausible guess is not, because it will be reviewed and published as fact: do not infer a field from another field, from the car itself, or from what you know about how a brand numbers its releases.

        Field notes:
        - brand is the toy manufacturer whose packaging this is — Hot Wheels, Matchbox, Tomica, Mini GT. It is never the real car's manufacturer. A Hot Wheels Honda Civic has brand "Hot Wheels".
        - casting_name is the car's name as printed on the card, including any leading year abbreviation: "'92 Honda Prelude".
        - year is the model year printed on the packaging. Packaging also carries a copyright year in the small print, which is often a different number and is not what this field wants.
        - series is the named release wave, such as "Night Burnerz". Many packages name none; that is a null, not a guess.
        - collector_number keeps its printed separator, as in "160/244".
        - toy_number is the manufacturer's alphanumeric product code from the small print, usually on the back.

        Score confidence per field between 0 and 1: how sure you are that the value you reported matches the printed text. Score any field you reported as null at 0. Distinguish "the logo fills the card and is unambiguous" from "the last digit could be a 3 or an 8" — a reviewer uses these scores to decide what to check by eye, so a uniform 0.9 across every field tells them nothing.
        PROMPT;

    public function __construct(private readonly Client $client) {}

    /**
     * @return array<string, mixed> Output conforming to app/AI/schemas/ocr_output.json
     *
     * @throws OcrFailedException
     */
    public function read(Submission $submission): array
    {
        $photos = $this->readablePhotos($submission);

        if ($photos === []) {
            throw new OcrFailedException(
                'No readable photos on this submission. Vision accepts JPEG, PNG, WebP and GIF; HEIC and HEIF are stored but cannot be read.'
            );
        }

        // Blank disables it. The parameter is Claude-API-only and the eligible
        // substitutes are per-model, so pointing OCR_MODEL somewhere that has
        // none needs a way to turn this off rather than a 400 on every read.
        $fallbacks = config('catalog.ocr.fallbacks') ?: null;

        // The beta namespace, because `fallbacks` only exists there. Everything
        // else about the call is identical to the stable endpoint.
        $message = $this->client->beta->messages->create(
            model: config('catalog.ocr.model'),
            maxTokens: config('catalog.ocr.max_tokens'),
            system: [[
                'type' => 'text',
                'text' => self::SYSTEM_PROMPT,
                // Identical on every submission, so it is the one part of the
                // request worth caching. The photos and hints that follow are
                // different every time and sit after this block.
                'cacheControl' => ['type' => 'ephemeral'],
            ]],
            messages: [['role' => 'user', 'content' => $this->content($submission, $photos)]],
            outputConfig: [
                'effort' => config('catalog.ocr.effort'),
                'format' => ['type' => 'json_schema', 'schema' => self::schema()],
            ],
            // "default" rather than a pinned model: the right substitute
            // depends on why the request was declined, and a named model is a
            // migration owed the day it's deprecated. On a policy decline the
            // API re-runs this same request on the substitute and returns its
            // answer, so a refusal only reaches the code below if the whole
            // chain declined.
            fallbacks: $fallbacks,
            betas: $fallbacks === null ? null : [self::FALLBACK_BETA],
        );

        // Checked before touching content: a declined request returns HTTP 200
        // with an empty or partial content array, so reading content[0] first
        // turns a refusal into a confusing type error.
        if ($message->stopReason === 'refusal') {
            // isset(), not ?->: stopDetails is a typed property the SDK leaves
            // uninitialized when the API omits it, and reading an uninitialized
            // typed property is a fatal Error rather than null.
            $category = isset($message->stopDetails) ? $message->stopDetails->category : null;

            throw new OcrFailedException(
                'The model declined to read these photos'.($category ? " ({$category})" : '').'.'
            );
        }

        if ($message->stopReason === 'max_tokens') {
            throw new OcrFailedException(
                'The model ran out of output tokens before finishing. Raise OCR_MAX_TOKENS or lower OCR_EFFORT.'
            );
        }

        return $this->decode($message->content);
    }

    /**
     * The schema, read from the file the model is also given, so there is
     * exactly one definition of this shape in the project.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return json_decode(file_get_contents(self::schemaPath()), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function schemaPath(): string
    {
        return app_path('AI/schemas/ocr_output.json');
    }

    /**
     * The photos worth sending, best first, capped by the configured budget.
     *
     * The cap exists because photos are base64-encoded into the request body: a
     * capture can hold eight 12 MB images, and sending all of them would be a
     * ~130 MB request for text that is legible in two.
     *
     * @return list<array{photo: SubmissionPhoto, media_type: string}>
     */
    private function readablePhotos(Submission $submission): array
    {
        return $submission->photos
            ->sortBy(function (SubmissionPhoto $photo): int {
                // Not `?: PHP_INT_MAX` — card_front is index 0, and 0 is falsy,
                // which would sort the most valuable photo last.
                $rank = array_search($photo->type, self::SLOT_PRIORITY, strict: true);

                return $rank === false ? PHP_INT_MAX : $rank;
            })
            ->map(fn (SubmissionPhoto $photo): ?array => ($mediaType = $this->mediaType($photo)) === null
                ? null
                : ['photo' => $photo, 'media_type' => $mediaType])
            ->filter()
            ->take((int) config('catalog.ocr.max_photos'))
            ->values()
            ->all();
    }

    /**
     * Derived from the stored path's extension rather than a column, because
     * submission_photos records no MIME type — Laravel names an upload from the
     * MIME type it detected, so the extension is that detection, persisted.
     *
     * Returns null for anything vision can't accept, which is how a HEIC photo
     * gets skipped instead of being sent as bytes the API will reject.
     */
    private function mediaType(SubmissionPhoto $photo): ?string
    {
        $extension = mb_strtolower(pathinfo($photo->path, PATHINFO_EXTENSION));

        return self::MEDIA_TYPES[$extension] ?? null;
    }

    /**
     * @param  list<array{photo: SubmissionPhoto, media_type: string}>  $photos
     * @return list<array<string, mixed>>
     */
    private function content(Submission $submission, array $photos): array
    {
        $content = [];

        foreach ($photos as $entry) {
            // Labelled before the image, which is the documented shape for
            // multi-image prompts: without it the model has to infer which
            // photo is the back of the card, and the small print only exists
            // on one of them.
            $content[] = [
                'type' => 'text',
                'text' => 'Photograph — '.str($entry['photo']->type)->headline()->lower().':',
            ];

            $content[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'mediaType' => $entry['media_type'],
                    'data' => base64_encode(
                        Storage::disk($entry['photo']->disk)->get($entry['photo']->path)
                    ),
                ],
            ];
        }

        $content[] = ['type' => 'text', 'text' => $this->hints($submission)];

        return $content;
    }

    /**
     * Whatever the admin typed at capture time, passed as context rather than
     * as answers. They are typed from memory with a car in one hand, so they
     * are wrong often enough that a model treating them as ground truth would
     * launder a typo into the catalogue.
     */
    private function hints(Submission $submission): string
    {
        $hints = collect([
            'Brand' => $submission->hint_brand,
            'Year' => $submission->hint_year,
            'Series' => $submission->hint_series,
        ])->filter();

        if ($hints->isEmpty()) {
            return 'Report what these photographs show.';
        }

        return 'The person who took these photographs also typed in: '
            .$hints->map(fn ($value, $label): string => "{$label} — {$value}")->implode('; ')
            .'. Treat that as context for what to look for, not as answers. '
            .'Where the packaging disagrees with it, report the packaging; where the packaging is unreadable, report null rather than falling back to what they typed.';
    }

    /**
     * @param  array<int, object>  $content
     * @return array<string, mixed>
     *
     * @throws OcrFailedException
     */
    private function decode(array $content): array
    {
        foreach ($content as $block) {
            if ($block->type !== 'text') {
                continue;
            }

            $decoded = json_decode($block->text, true);

            if (! is_array($decoded)) {
                throw new OcrFailedException('The model returned text that is not JSON.');
            }

            return $this->validated($decoded);
        }

        throw new OcrFailedException('The model returned no text block to read.');
    }

    /**
     * Structured outputs guarantee the response matches the schema, so this is
     * a tripwire rather than a validator — enough to catch the schema file and
     * this class disagreeing, without adding a JSON Schema dependency to
     * re-check a guarantee the API already gives. The one thing genuinely worth
     * checking is the bound on confidence, which the API's schema subset can't
     * express (no minimum/maximum) and so is not covered by that guarantee.
     *
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     *
     * @throws OcrFailedException
     */
    private function validated(array $output): array
    {
        if (($output['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new OcrFailedException('The model returned an unexpected schema version.');
        }

        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $output)) {
                throw new OcrFailedException("The model omitted the field '{$field}'.");
            }

            $score = $output['confidence'][$field] ?? null;

            if (! is_numeric($score) || $score < 0 || $score > 1) {
                throw new OcrFailedException("The model scored '{$field}' outside 0 to 1.");
            }
        }

        return $output;
    }
}
