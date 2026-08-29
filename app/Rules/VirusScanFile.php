<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class VirusScanFile implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * This rule integrates with ClamAV when available.
     * If ClamAV is not installed, the check is silently skipped.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value || ! file_exists($value->path())) {
            return;
        }

        // Check if exec is available and clamscan binary is on the system
        if (! function_exists('exec')) {
            return; // Skip silently if exec is disabled
        }

        $clamscanPath = $this->findClamscan();

        if ($clamscanPath === null) {
            // ClamAV not installed — skip scanning silently
            return;
        }

        // Run clamscan on the uploaded file
        $filepath = escapeshellarg($value->path());
        exec("$clamscanPath --no-summary $filepath 2>&1", $output, $exitCode);

        // clamscan exit codes: 0 = clean, 1 = virus found, 2 = error
        if ($exitCode === 1) {
            $fail('The '.$attribute.' contains malware and cannot be uploaded.');
        }
    }

    /**
     * Attempt to locate the clamscan binary.
     */
    protected function findClamscan(): ?string
    {
        if (! function_exists('exec')) {
            return null;
        }

        // Check common paths
        $paths = [
            '/usr/bin/clamscan',
            '/usr/local/bin/clamscan',
            'clamscan', // relies on PATH
        ];

        foreach ($paths as $path) {
            exec("which $path 2>/dev/null", $output, $exitCode);
            if ($exitCode === 0) {
                return $path;
            }
        }

        // Windows fallback
        if (PHP_OS_FAMILY === 'Windows') {
            exec('where clamscan 2>nul', $output, $exitCode);
            if ($exitCode === 0 && ! empty($output[0])) {
                return trim($output[0]);
            }
        }

        return null;
    }
}
