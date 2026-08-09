<?php

namespace App\Http\Requests;

use App\Models\SubmissionPhoto;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * One capture session posted from the phone: the photos of a single package,
 * plus whatever context the admin could be bothered to type in.
 *
 * The photo fields are named slots rather than one `photos[]` array so each
 * upload arrives already typed. submission_photos.type is free text on purpose
 * — which angles exist is brand-dependent — so the shortlist lives here, in the
 * UI layer, exactly where that migration's comment says it should.
 */
class StoreSubmissionRequest extends FormRequest
{
    /**
     * Single-file slots, mapped to the SubmissionPhoto type they produce.
     */
    private const SINGLE_SLOTS = [
        'card_front' => SubmissionPhoto::TYPE_CARD_FRONT,
        'card_back' => SubmissionPhoto::TYPE_CARD_BACK,
    ];

    public function authorize(): bool
    {
        // The route sits behind `auth` + `admin`; there is no second question to
        // ask here while the admin is the only account that exists.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $photo = [
            'file',
            // Not the `image` rule: it leans on getimagesize(), which doesn't
            // know HEIC/HEIF in most PHP builds. iOS Safari usually transcodes
            // to JPEG on upload, but "usually" isn't a validation strategy, and
            // a rejected photo of a car already back in its case is a reshoot.
            'mimetypes:image/jpeg,image/png,image/webp,image/heic,image/heif',
            'max:'.config('catalog.capture.max_photo_kilobytes'),
        ];

        return [
            'card_front' => ['nullable', ...$photo],
            'card_back' => ['nullable', ...$photo],
            'loose' => ['nullable', 'array', 'max:6'],
            'loose.*' => $photo,

            'hint_brand' => ['nullable', 'string', 'max:255'],
            // Matches submissions_hint_year_range. Bounced here as a message
            // rather than by the CHECK constraint as a 500.
            'hint_year' => ['nullable', 'integer', 'between:1900,2100'],
            'hint_series' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                // Every slot is individually optional — you might have a loose car
                // with no card at all — but a submission with no photos is nothing
                // for the pipeline to read.
                if ($validator->errors()->isEmpty() && $this->photos() === []) {
                    $validator->errors()->add('card_front', 'Take at least one photo before submitting.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'card_front' => 'card front photo',
            'card_back' => 'card back photo',
            'loose.*' => 'loose photo',
            'hint_brand' => 'brand',
            'hint_year' => 'year',
            'hint_series' => 'series',
        ];
    }

    /**
     * bootstrap/app.php restricts JSON exception rendering to `api/*` on
     * purpose, so that a browser sending an unhelpful Accept header still gets
     * redirect-with-errors on the HTML forms. The capture upload is the one
     * place that genuinely posts by XHR, and an HTML redirect body is no use to
     * the progress bar — so it opts in here rather than by widening the global
     * rule. An explicit response on the exception wins over that rule.
     */
    protected function failedValidation(ValidatorContract $validator): void
    {
        if (! $this->expectsJson()) {
            parent::failedValidation($validator);
        }

        throw new ValidationException($validator, response()->json([
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors()->messages(),
        ], 422));
    }

    /**
     * The uploaded photos, flattened and paired with the type each slot implies.
     *
     * Defensive about what's actually an UploadedFile because after() callbacks
     * still run when an earlier rule has already failed.
     *
     * @return list<array{file: UploadedFile, type: string}>
     */
    public function photos(): array
    {
        $photos = [];

        foreach (self::SINGLE_SLOTS as $slot => $type) {
            $file = $this->file($slot);

            if ($file instanceof UploadedFile) {
                $photos[] = ['file' => $file, 'type' => $type];
            }
        }

        foreach ((array) $this->file('loose', []) as $file) {
            if ($file instanceof UploadedFile) {
                $photos[] = ['file' => $file, 'type' => SubmissionPhoto::TYPE_LOOSE];
            }
        }

        return $photos;
    }

    /**
     * @return array<string, mixed>
     */
    public function hints(): array
    {
        return [
            'hint_brand' => $this->string('hint_brand')->trim()->value() ?: null,
            'hint_year' => $this->integer('hint_year') ?: null,
            'hint_series' => $this->string('hint_series')->trim()->value() ?: null,
        ];
    }
}
