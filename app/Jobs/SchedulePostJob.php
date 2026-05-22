<?php

namespace App\Jobs;

use App\Models\PostTarget;
use App\Services\Platforms\TikTokPublishService;
use App\Services\Platforms\TikTokService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SchedulePostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Retry up to 3 times with increasing backoff.
     * Backoff accounts for TikTok's rate limit (6 req/min per user).
     */
    public $tries   = 3;
    public $backoff = [60, 180, 600];

    /**
     * Allow up to 5 minutes per attempt (video uploads can be large).
     */
    public $timeout = 300;

    protected $targetId;

    public function __construct($targetId)
    {
        $this->targetId = $targetId;
    }

    public function handle()
    {
        $target = PostTarget::with('socialAccount', 'post')->findOrFail($this->targetId);

        if ($target->status !== 'pending' && $target->status !== 'failed') {
            Log::info("SchedulePostJob: target {$this->targetId} status is '{$target->status}', skipping.");
            return;
        }

        // If the post was rescheduled into the future, ignore this early job firing
        // The Cron (postar:publish-scheduled) will pick it up at the correct future time.
        if ($target->post && $target->post->scheduled_at && $target->post->scheduled_at->isFuture()) {
            Log::info("SchedulePostJob: post scheduled_at is in the future, deferring.", [
                'target_id'    => $this->targetId,
                'scheduled_at' => $target->post->scheduled_at,
            ]);
            return;
        }

        // ── Pre-flight checks ────────────────────────────────────────────────
        $this->validatePreConditions($target);

        $target->update(['status' => 'uploading']);

        $platform = $target->socialAccount->platform;

        try {
            // Refresh the token before publishing
            $this->refreshTokenIfNeeded($target->socialAccount);
            $target->socialAccount->refresh(); // reload the latest token from DB

            $service = $this->getPublishService($platform);
            $result  = $service->publish($target);

            // Handle both 'published' and 'processing' outcomes
            $finalStatus = ($result['status'] ?? 'published') === 'processing'
                ? 'processing'
                : 'published';

            $target->update([
                'status'           => $finalStatus,
                'platform_post_id' => $result['id'] ?? null,
                'published_at'     => $finalStatus === 'published' ? now() : null,
                'error_message'    => null,  // Clear any previous error
            ]);

            $this->updateParentPostStatus($target->post_id);

            Log::info("SchedulePostJob: target {$this->targetId} completed with status '{$finalStatus}'.");

        } catch (\Exception $e) {
            Log::error("SchedulePostJob: target {$this->targetId} failed.", [
                'error'   => $e->getMessage(),
                'attempt' => $this->attempts(),
                'max'     => $this->tries,
            ]);

            $target->update([
                'status'        => 'failed',
                'error_message' => $this->truncateMessage($e->getMessage(), 500),
            ]);
            $this->updateParentPostStatus($target->post_id);

            // Determine if the error is retryable
            if ($this->isPermanentFailure($e)) {
                Log::warning("SchedulePostJob: permanent failure — will not retry.", [
                    'target_id' => $this->targetId,
                ]);
                $this->fail($e);
                return;
            }

            throw $e; // Let Laravel retry
        }
    }

    // -------------------------------------------------------------------------
    // Pre-flight validation
    // -------------------------------------------------------------------------

    private function validatePreConditions(PostTarget $target): void
    {
        $post = $target->post;

        // Check that the video file exists on disk
        if ($post->video_path && !Storage::disk('public')->exists($post->video_path)) {
            throw new \RuntimeException(
                "Video file not found on disk: {$post->video_path}. " .
                "The file may have been deleted or moved."
            );
        }

        // Check social account is active
        if (!$target->socialAccount->is_active) {
            throw new \RuntimeException(
                "Social account {$target->socialAccount->id} is deactivated."
            );
        }
    }

    // -------------------------------------------------------------------------
    // Token refresh — actually calls the platform service
    // -------------------------------------------------------------------------

    private function refreshTokenIfNeeded($account): void
    {
        // Refresh if token is already expired OR will expire in the next 15 min
        $expiresSoon = $account->token_expires_at
            && now()->addMinutes(15)->greaterThanOrEqualTo($account->token_expires_at);

        if (!$expiresSoon) {
            return;
        }

        $service = $this->getAuthService($account->platform);

        if ($service === null) {
            Log::warning('No auth service available for token refresh', [
                'platform'   => $account->platform,
                'account_id' => $account->id,
            ]);
            return;
        }

        try {
            Log::info('Refreshing token before publish', [
                'platform'   => $account->platform,
                'account_id' => $account->id,
            ]);
            $service->refreshToken($account);
        } catch (\Exception $e) {
            // Log but don't rethrow — the publish will fail with its own auth error
            // which gives a clearer message
            Log::error('Token refresh failed before publish', [
                'platform'   => $account->platform,
                'account_id' => $account->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Error classification
    // -------------------------------------------------------------------------

    /**
     * Determine if an error is permanent (no point retrying).
     */
    private function isPermanentFailure(\Exception $e): bool
    {
        $message = strtolower($e->getMessage());

        $permanentPatterns = [
            'video file not found',
            'social account',
            'deactivated',
            'scope_not_authorized',
            'access_token_invalid',
            'user_not_found',
            'spam_risk_user_banned',
            'unsupported platform',
        ];

        foreach ($permanentPatterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Factories
    // -------------------------------------------------------------------------

    private function getAuthService(string $platform): ?object
    {
        return match ($platform) {
            'tiktok' => app(TikTokService::class),
            default  => null, // YouTube auth service not yet implemented
        };
    }

    private function getPublishService(string $platform): object
    {
        return match ($platform) {
            'tiktok'    => new TikTokPublishService(),
            'youtube'   => app(\App\Services\YouTubePublishService::class),
            default     => throw new \Exception("Unsupported platform: {$platform}"),
        };
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function updateParentPostStatus($postId): void
    {
        $post = \App\Models\Post::with('targets')->find($postId);
        if (!$post) return;

        $hasFailed     = $post->targets->where('status', 'failed')->count() > 0;
        $hasProcessing = $post->targets->whereIn('status', ['pending', 'uploading', 'processing'])->count() > 0;

        if ($hasFailed && !$hasProcessing) {
            $post->status = 'failed';
        } elseif ($hasProcessing) {
            $post->status = 'publishing';
        } else {
            $post->status = 'published';
        }

        $post->save();
    }

    /**
     * Truncate error messages so they don't blow up the DB column.
     */
    private function truncateMessage(string $message, int $maxLength): string
    {
        return strlen($message) > $maxLength
            ? substr($message, 0, $maxLength - 3) . '...'
            : $message;
    }
}
