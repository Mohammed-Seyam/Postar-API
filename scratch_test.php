<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$target = \App\Models\PostTarget::whereHas('socialAccount', function($q) {
    $q->where('platform', 'youtube');
})->orderBy('id', 'desc')->first();

if ($target) {
    $target->update(['status' => 'pending', 'platform_post_id' => null, 'published_at' => null]);
    echo "Re-queued target {$target->id}\n";
    dispatch_sync(new \App\Jobs\SchedulePostJob($target->id));
    echo "Done processing\n";
} else {
    echo "Target not found\n";
}
