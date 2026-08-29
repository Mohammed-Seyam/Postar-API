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
    public $tries = 3;

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
        $target = PostTarget::with('socialAccount', 'post.media')->findOrFail($this->targetId);

        if ($target->status !== 'pending' && $target->status !== 'failed') {
            Log::info("SchedulePostJob: target {$this->targetId} status is '{$target->status}', skipping.");

            return;
        }

        // If the post was rescheduled into the future, ignore this early job firing
        // The Cron (postar:publish-scheduled) will pick it up at the correct future time.
        if ($target->post && $target->post->scheduled_at && $target->post->scheduled_at->isFuture()) {
            Log::info('SchedulePostJob: post scheduled_at is in the future, deferring.', [
                'target_id' => $this->targetId,
                'scheduled_at' => $target->post->scheduled_at,
            ]);

            return;
        }

        $platform = $target->socialAccount->platform;

        try {
            // ── Pre-flight checks ────────────────────────────────────────────
            // Inside the try so failures mark the target 'failed' (and permanent
            // ones like capability/file errors don't get pointlessly retried).
            $this->validatePreConditions($target);

            $target->update(['status' => 'uploading']);

            // Refresh the token before publishing
            $this->refreshTokenIfNeeded($target->socialAccount);
            $target->socialAccount->refresh(); // reload the latest token from DB

            $service = $this->getPublishService($platform);
            $result = $service->publish($target);

            // Handle both 'published' and 'processing' outcomes
            $finalStatus = ($result['status'] ?? 'published') === 'processing'
                ? 'processing'
                : 'published';

            $platformPostId = null;
            if ($platform === 'tiktok') {
                $platformPostId = $result['real_id'] ?? $result['id'] ?? null;
            } else {
                $platformPostId = $result['id'] ?? null;
            }

            $target->update([
                'status' => $finalStatus,
                'platform_post_id' => $platformPostId,
                'published_at' => $finalStatus === 'published' ? now() : null,
                'error_message' => null,  // Clear any previous error
            ]);

            $this->updateParentPostStatus($target->post_id);

            Log::info("SchedulePostJob: target {$this->targetId} completed with status '{$finalStatus}'.");

        } catch (\Exception $e) {
            $this->logPublishingError("SchedulePostJob: target {$this->targetId} failed.", [
                'error_message' => $e->getMessage(),
                'exception_class' => get_class($e),
                'attempt' => $this->attempts(),
                'max' => $this->tries,
                'target_id' => $this->targetId,
                'post_id' => $target->post_id ?? null,
                'platform' => $target->socialAccount->platform ?? null,
                'username' => $target->socialAccount->username ?? null,
                'trace' => $e->getTraceAsString(),
            ]);

            $target->update([
                'status' => 'failed',
                'error_message' => $this->truncateMessage($e->getMessage(), 500),
            ]);
            $this->updateParentPostStatus($target->post_id);

            // Determine if the error is retryable
            if ($this->isPermanentFailure($e)) {
                Log::warning('SchedulePostJob: permanent failure — will not retry.', [
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
        $media = $post->media;

        // A post must carry at least one media item.
        if ($media->isEmpty()) {
            throw new \RuntimeException("Post {$post->id} has no media to publish.");
        }

        // Every media file must exist on disk / R2.
        foreach ($media as $item) {
            if ($item->path
                && ! Storage::disk('public')->exists($item->path)
                && ! Storage::disk('r2')->exists($item->path)) {
                throw new \RuntimeException(
                    "Media file not found on disk or R2: {$item->path}. ".
                    'The file may have been deleted or moved.'
                );
            }
        }

        // The media set must satisfy the target platform's capabilities.
        $platform = $target->socialAccount->platform;
        $capabilityErrors = \App\Services\Platforms\PlatformCapabilities::validate($platform, $media);
        if (! empty($capabilityErrors)) {
            throw new \RuntimeException(
                "Media incompatible with {$platform}: ".implode(' ', $capabilityErrors)
            );
        }

        // Check social account is active
        if (! $target->socialAccount->is_active) {
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

        if (! $expiresSoon) {
            return;
        }

        $service = $this->getAuthService($account->platform);

        if ($service === null) {
            Log::warning('No auth service available for token refresh', [
                'platform' => $account->platform,
                'account_id' => $account->id,
            ]);

            return;
        }

        try {
            Log::info('Refreshing token before publish', [
                'platform' => $account->platform,
                'account_id' => $account->id,
            ]);
            $service->refreshToken($account);
        } catch (\Exception $e) {
            // Log but don't rethrow — the publish will fail with its own auth error
            // which gives a clearer message
            Log::error('Token refresh failed before publish', [
                'platform' => $account->platform,
                'account_id' => $account->id,
                'error' => $e->getMessage(),
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
            'media file not found',
            'media incompatible',
            'has no media',
            'social account',
            'deactivated',
            'scope_not_authorized',
            'access_token_invalid',
            'user_not_found',
            'spam_risk_user_banned',
            'unsupported platform',
            'unaudited_client_can_only_post_to_private_accounts',
            'development/unaudited status',
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
            default => null, // YouTube auth service not yet implemented
        };
    }

    private function getPublishService(string $platform): \App\Services\Platforms\PublishServiceContract
    {
        return match ($platform) {
            'tiktok' => new TikTokPublishService,
            'youtube' => app(\App\Services\YouTubePublishService::class),
            default => throw new \Exception("Unsupported platform: {$platform}"),
        };
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function updateParentPostStatus($postId): void
    {
        $post = \App\Models\Post::with('targets.socialAccount', 'user')->find($postId);
        if (! $post) {
            return;
        }

        $hasFailed = $post->targets->where('status', 'failed')->count() > 0;
        $hasProcessing = $post->targets->whereIn('status', ['pending', 'uploading', 'processing'])->count() > 0;

        $previousStatus = $post->status;

        if ($hasFailed && ! $hasProcessing) {
            $post->status = 'failed';
        } elseif ($hasProcessing) {
            $post->status = 'publishing';
        } else {
            $post->status = 'published';
        }

        $post->save();

        if ($previousStatus !== $post->status && in_array($post->status, ['published', 'failed'])) {
            $user = $post->user;
            $settings = $user->settings ?? [];
            $emailNotifications = $settings['email_notifications'] ?? true;
            
            if ($emailNotifications && $user->email) {
                try {
                    \Illuminate\Support\Facades\Mail::to($user->email)
                        ->send(new \App\Mail\PostPublishStatusMail($post, $post->status));
                    Log::info("Sent publish status ({$post->status}) email to {$user->email} for post {$post->id}");
                } catch (\Exception $e) {
                    Log::error("Failed to send publish status email for post {$post->id}: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Truncate error messages so they don't blow up the DB column.
     */
    private function truncateMessage(string $message, int $maxLength): string
    {
        return strlen($message) > $maxLength
            ? substr($message, 0, $maxLength - 3).'...'
            : $message;
    }

    /**
     * Log detailed publishing error information to standard Log and dedicated publishing_errors.log file.
     */
    private function logPublishingError(string $message, array $context = []): void
    {
        Log::error($message, $context);

        try {
            $logPath = storage_path('logs/publishing_errors.log');
            $logData = json_encode([
                'timestamp' => now()->toIso8601String(),
                'message' => $message,
                'context' => $context,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            file_put_contents($logPath, $logData . PHP_EOL . str_repeat('=', 80) . PHP_EOL, FILE_APPEND);
        } catch (\Exception $e) {
            // Silently ignore log write failures to avoid failing the application flow
        }
    }
}
