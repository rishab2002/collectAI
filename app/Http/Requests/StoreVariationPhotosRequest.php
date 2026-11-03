<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\TakesPhotoSlots;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Photos added to a variation that is already in the catalog.
 */
class StoreVariationPhotosRequest extends FormRequest
{
    use TakesPhotoSlots;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->photoSlotRules();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->photoSlotAttributes();
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isEmpty() && $this->photos() === []) {
                    $validator->errors()->add('card_front', 'Choose at least one photo to add.');
                }
            },
        ];
    }
}
