<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Crypt;

$state = 'eyJpdiI6InFDV3pXQklTRmsxdThaYVQrOExLSUE9PSIsInZhbHVlIjoid1NNZnMwVk4ydjZqY2wxV3VaeG5PSElSTE5JUjNHQVlheGpSSUhNZFRnSE1yWU03My9sNWJTMUlZREFjQU9EQlNXSCtFakRJWFJWYlpuZGxWQ3hMeXJVSVliMHpIMHhEenIzaE9WSVVXdFBoay9DWHdQNkdtN0Nwc2FXa0NaMEJYSFhqQ3JMdU43amtzVU1OTTUvUk4ybVZ0Z3ZyTnBldWZPdFBmQXdpS2ZFPSIsIm1hYyI6IjFlODkxNTk0ZTI4NmQ1YTJmYzU5NWEyMjY5ZjVmMjlmZmM2Y2RiYjdjOGNiY2E1NTJkM2E0ZWU4ZDkxYzg0NzYiLCJ0YWciOiIifQ==';

try {
    $decrypted = Crypt::decrypt($state);
    echo "Decrypted state: " . json_encode($decrypted) . "\n";
} catch (\Exception $e) {
    echo "Error decrypting state: " . $e->getMessage() . "\n";
}
