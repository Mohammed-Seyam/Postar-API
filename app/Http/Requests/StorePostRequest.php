<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePostRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'video_id'   => 'required|string|exists:posts,id',
            'platforms'  => 'required|array|min:1',
            'platforms.*'=> 'required|string|in:tiktok,youtube',
            'publish_at' => 'nullable|date|after_or_equal:now',
            'caption'    => 'nullable|string',
        ];
    }
}
