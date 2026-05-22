<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidVideoFile implements ValidationRule
{
    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Check file exists
        if (!$value || !file_exists($value->path())) {
            $fail('The file could not be found.');
            return;
        }

        // Check MIME type via the upload object
        $mimeType = $value->getMimeType();
        $validMimes = ['video/mp4', 'video/quicktime'];

        if (!in_array($mimeType, $validMimes)) {
            $fail('The ' . $attribute . ' must be a valid video file (MP4 or MOV).');
            return;
        }

        // Check file size — 150 MB
        if ($value->getSize() > 157286400) {
            $fail('The ' . $attribute . ' must not be larger than 150MB.');
            return;
        }

        // Check file extension
        $extension = strtolower($value->getClientOriginalExtension());
        if (!in_array($extension, ['mp4', 'mov'])) {
            $fail('The ' . $attribute . ' must have an .mp4 or .mov extension.');
            return;
        }

        // Validate actual file content via magic bytes (finfo)
        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $detected = finfo_file($finfo, $value->path());
        finfo_close($finfo);

        if (!in_array($detected, $validMimes)) {
            $fail('The ' . $attribute . ' file content is invalid.');
            return;
        }
    }
}
