<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreWaitlistRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255', 'unique:waitlist_signups,email'],
            'name' => ['nullable', 'string', 'max:255'],
            'referral_code' => ['nullable', 'string', 'exists:waitlist_signups,referral_code'],
            'source' => ['nullable', 'string', 'max:255'],
            'cf-turnstile-response' => ['required', 'string'],
        ];
    }
}
