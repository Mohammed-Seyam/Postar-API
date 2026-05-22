<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$job = DB::table('jobs')->first();
if ($job) {
    $payload = json_decode($job->payload);
    echo "Job Class: " . ($payload->displayName ?? 'unknown') . PHP_EOL;
    echo "Queue: " . $job->queue . PHP_EOL;
    echo "Attempts: " . $job->attempts . PHP_EOL;
    echo "Available at: " . date('Y-m-d H:i:s', $job->available_at) . PHP_EOL;
    echo "Created at: " . date('Y-m-d H:i:s', $job->created_at) . PHP_EOL;
    
    // Extract target ID from the command data  
    $command = unserialize($payload->data->command);
    echo "Target ID in job: " . ($command->targetId ?? 'N/A') . PHP_EOL;
} else {
    echo "No pending jobs" . PHP_EOL;
}

// Also show the latest failed job error summary
$failed = DB::table('failed_jobs')->orderBy('failed_at', 'desc')->limit(3)->get(['uuid', 'payload', 'failed_at']);
echo PHP_EOL . "=== Latest Failed Jobs ===" . PHP_EOL;
foreach ($failed as $f) {
    $p = json_decode($f->payload);
    echo "UUID: {$f->uuid} | Class: " . ($p->displayName ?? '?') . " | Failed: {$f->failed_at}" . PHP_EOL;
}
