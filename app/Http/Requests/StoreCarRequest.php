<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\DescribesAVariation;
use App\Http\Requests\Concerns\TakesPhotoSlots;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A car typed in by hand: a new casting, its first release and variation, and
 * optionally its photos.
 *
 * A casting must say what it is — a real make and model, or fantasy. That is the
 * long-standing `is_fantasy` rule: a real casting with no model can't go live,
 * and a hand-entered car that silently landed pending would look like the form
 * had lost it.
 */
class StoreCarRequest extends FormRequest
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
            'casting_name' => ['required', 'string', 'max:255'],
            'casting_notes' => ['nullable', 'string', 'max:5000'],
            'is_fantasy' => ['boolean'],

            'make' => ['exclude_if:is_fantasy,1', 'required', 'string', 'max:255'],
            'model' => ['exclude_if:is_fantasy,1', 'required', 'string', 'max:255'],
            // A generation is one claim — code plus years — so the code and end
            // year only mean something alongside a start year (see after()).
            // Both stay optional: not every generation has a manufacturer code,
            // and a current one has no end year.
            'generation_start_year' => ['exclude_if:is_fantasy,1', 'nullable', 'integer', 'between:1880,2100'],
            'generation_end_year' => ['exclude_if:is_fantasy,1', 'nullable', 'integer', 'between:1880,2100', 'gte:generation_start_year'],
            'chassis_code' => ['exclude_if:is_fantasy,1', 'nullable', 'string', 'max:255'],

            ...$this->variationRules(),
            ...$this->photoSlotRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'make.required' => 'Give the real make, or tick “fantasy casting” if there is no real car behind it.',
            'model.required' => 'Give the real model, or tick “fantasy casting” if there is no real car behind it.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'casting_name' => 'casting name',
            'generation_start_year' => 'generation start year',
            'generation_end_year' => 'generation end year',
            'chassis_code' => 'chassis code',
            ...$this->variationAttributes(),
            ...$this->photoSlotAttributes(),
        ];
    }

    /**
     * The generation fields only make sense together; a chassis code with no
     * start year is dropped by the service rather than stored as a half-claim,
     * and this tells the admin so instead of silently discarding it.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function ($validator): void {
                if ($this->boolean('is_fantasy')) {
                    return;
                }

                $hasDetail = filled($this->input('chassis_code')) || filled($this->input('generation_end_year'));

                if ($hasDetail && blank($this->input('generation_start_year'))) {
                    $validator->errors()->add(
                        'generation_start_year',
                        'A generation needs its start year — the chassis code and end year are recorded with it or not at all.',
                    );
                }
            },
        ];
    }
}
