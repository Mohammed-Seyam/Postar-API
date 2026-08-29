<?php

namespace App\Console\Commands;

use App\Models\PostTarget;
use App\Services\Platforms\TikTokService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CheckTikTokProcessingPosts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'postar:check-processing-posts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Poll TikTok status for posts that are currently in processing state to extract their final video ID';

    /**
     * Execute the console command.
     */
    public function handle(TikTokService $tiktokService)
    {
        $this->info('Checking for processing TikTok posts...');

        $targets = PostTarget::where('status', 'processing')
            ->whereHas('socialAccount', function ($query) {
                $query->where('platform', 'tiktok');
            })
            ->with(['socialAccount', 'post'])
            ->get();

        if ($targets->isEmpty()) {
            $this->info('No processing TikTok posts found.');

            return;
        }

        $this->info("Found {$targets->count()} post(s) in processing. Polling TikTok...");

        $failureStatuses = [
            'FAILED',
            'PUBLISH_FAILED',
            'VIDEO_PULL_FAILED',
            'SPAM_RISK_TOO_MANY_POSTS',
            'SPAM_RISK_USER_BANNED_FROM_POSTING',
            'BLOCKED_BY_CREATOR',
            'AUDITED_FAILED_MUSIC',
            'AUDITED_FAILED_GENERAL',
        ];

        foreach ($targets as $target) {
            $publishId = $target->platform_post_id;
            if (! $publishId) {
                Log::warning("CheckTikTokProcessingPosts: target {$target->id} has status processing but missing platform_post_id (publish_id). skipping.");

                continue;
            }

            $account = $target->socialAccount;

            try {
                // Get valid access token (refreshes if needed)
                $accessToken = $tiktokService->getValidToken($account);

                $response = Http::withToken($accessToken)
                    ->post('https://open.tiktokapis.com/v2/post/publish/status/fetch/', [
                        'publish_id' => $publishId,
                    ]);

                if ($response->failed()) {
                    Log::error("CheckTikTokProcessingPosts: target {$target->id} API call failed", [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    continue;
                }

                $status = $response->json('data.status');

                Log::info("CheckTikTokProcessingPosts: target {$target->id} status checked", [
                    'publish_id' => $publishId,
                    'status' => $status,
                ]);

                if ($status === 'PUBLISH_COMPLETE') {
                    $postIds = $response->json('data.publicaly_available_post_id', []);
                    $realPostId = ! empty($postIds) ? $postIds[0] : null;

                    $target->update([
                        'status' => 'published',
                        'platform_post_id' => $realPostId,
                        'published_at' => now(),
                        'error_message' => null,
                    ]);

                    $this->updateParentPostStatus($target->post_id);
                    $this->info("  ✓ Target ID {$target->id} successfully published with Video ID: {$realPostId}");

                } elseif (in_array($status, $failureStatuses)) {
                    $reason = $response->json('data.fail_reason') ?? $status;

                    $target->update([
                        'status' => 'failed',
                        'error_message' => substr($reason, 0, 500),
                    ]);

                    $this->updateParentPostStatus($target->post_id);
                    $this->error("  ✗ Target ID {$target->id} failed: {$reason}");
                } else {
                    $this->line("  - Target ID {$target->id} still processing (status: {$status})");
                }

            } catch (\Throwable $e) {
                Log::error("CheckTikTokProcessingPosts: failed to check target {$target->id}", [
                    'error' => $e->getMessage(),
                ]);
                $this->error("  ✗ Error checking target ID {$target->id}: ".$e->getMessage());
            }
        }

        $this->info('Done.');
    }

    private function updateParentPostStatus($postId): void
    {
        $post = \App\Models\Post::with('targets')->find($postId);
        if (! $post) {
            return;
        }

        $hasFailed = $post->targets->where('status', 'failed')->count() > 0;
        $hasProcessing = $post->targets->whereIn('status', ['pending', 'uploading', 'processing'])->count() > 0;

        if ($hasFailed && ! $hasProcessing) {
            $post->status = 'failed';
        } elseif ($hasProcessing) {
            $post->status = 'publishing';
        } else {
            $post->status = 'published';
        }

        $post->save();
    }
}
