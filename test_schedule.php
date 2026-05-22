<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Post;
use App\Models\PostTarget;
use App\Jobs\SchedulePostJob;
use Illuminate\Support\Facades\DB;

$postId = '019dbcac-cb66-7347-8816-b0e3522c286c';
$accountId = 2; // TikTok account id

$post = Post::findOrFail($postId);
echo "Found post: {$post->title}\n";

DB::beginTransaction();
try {
    $post->update([
        'status' => 'scheduled',
        'scheduled_at' => now(), // Publish immediately
    ]);

    $target = $post->targets()->updateOrCreate(
        ['social_account_id' => $accountId],
        [
            'status'           => 'pending',
            'error_message'    => null,
            'platform_post_id' => null,
            'published_at'     => null,
        ]
    );

    SchedulePostJob::dispatch($target->id);
    DB::commit();
    echo "Successfully dispatched SchedulePostJob for target: {$target->id}\n";
} catch (\Exception $e) {
    DB::rollBack();
    echo "Error: " . $e->getMessage() . "\n";
}
