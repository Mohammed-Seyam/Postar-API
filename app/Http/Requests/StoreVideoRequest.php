<?php

namespace App\Http\Requests;

use App\Rules\ValidVideoFile;
use App\Rules\VirusScanFile;
use Illuminate\Foundation\Http\FormRequest;

class StoreVideoRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'video' => ['required', 'file', 'mimetypes:video/mp4,video/quicktime', 'max:153600', new ValidVideoFile, new VirusScanFile], // 150MB max
            'title' => ['nullable', 'string', 'max:255'],
            'thumbnail' => ['nullable', 'image', 'max:10240'], // 10MB max
        ];
    }
}
