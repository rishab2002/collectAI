<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class RegisterRequest extends FormRequest
{
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
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            // Password::defaults() is set in AppServiceProvider: length and
            // character classes everywhere, a breached-password check in
            // production.
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            // A field people never see and bots fill in. Not a defence against a
            // determined attacker — the rate limit is — but it stops the cheap
            // form-spraying that would otherwise make most of the accounts.
            'website' => ['prohibited'],
        ];
    }

    /**
     * Emails are compared case-insensitively everywhere, so they are stored
     * lower-cased: "Rish@…" and "rish@…" must not become two accounts.
     */
    public function email(): string
    {
        return mb_strtolower(trim($this->string('email')->value()));
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => $this->email()]);
    }

    /**
     * A bot that filled the honeypot gets a generic failure, not a message
     * telling it which field gave it away.
     */
    protected function failedValidation(ValidatorContract $validator): void
    {
        if ($validator->errors()->has('website')) {
            throw ValidationException::withMessages(['email' => 'We could not create that account.']);
        }

        parent::failedValidation($validator);
    }
}
