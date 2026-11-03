<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\DescribesAVariation;
use App\Http\Requests\Concerns\TakesPhotoSlots;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Another release or variation of a casting already in the catalog — a new
 * colour, a new year, the same tool sold under a different brand.
 */
class StoreVariationRequest extends FormRequest
{
    use DescribesAVariation, TakesPhotoSlots;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Scoped to the casting in the URL, so a version id from another
            // casting is a validation error rather than a release filed under
            // the wrong tool.
            'casting_version_id' => [
                'nullable',
                'integer',
                Rule::exists('casting_versions', 'id')->where('casting_id', $this->route('casting')->getKey()),
            ],
            ...$this->variationRules(),
            ...$this->photoSlotRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'casting_version_id' => 'tooling version',
            ...$this->variationAttributes(),
            ...$this->photoSlotAttributes(),
        ];
    }
}
