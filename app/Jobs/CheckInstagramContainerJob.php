<?php

namespace App\Jobs;

use App\Models\PostTarget;
use App\Services\Platforms\InstagramPublishService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Polls an Instagram Reels media container until Meta finishes processing it,
 * then publishes it. Non-blocking: while the container is IN_PROGRESS the job
 * releases itself back onto the queue (about once a minute) instead of sleeping.
 *
 * Gives up after services.instagram.poll_max_attempts polls (about 5 minutes).
 */
class CheckInstagramContainerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Max transient HTTP errors tolerated before giving up. */
    public $maxExceptions = 3;

    public $backoff = 60;

    public $timeout = 120;

    public function __construct(public string $targetId) {}

    /**
     * Polls (releases) + a few retries for transient errors.
     */
    public function tries(): int
    {
        return $this->maxPolls() + $this->maxExceptions;
    }

    public function handle(InstagramPublishService $publisher): void
    {
        $target = PostTarget::with('socialAccount', 'post')->find($this->targetId);

        if (! $target || $target->status !== 'processing') {
            return; // already finalized (or deleted)
        }

        $containerId = $target->platform_post_id;
        if (! $containerId) {
            $this->markFailed($target, 'Instagram container id is missing.');

            return;
        }

        $account = $target->socialAccount;
        $status = $publisher->getContainerStatus($account, $containerId);
        $code = strtoupper((string) ($status['status_code'] ?? ''));

        Log::info('Instagram container status', [
            'target_id' => $target->id,
            'container_id' => $containerId,
            'status_code' => $code,
            'attempt' => $this->attempts(),
        ]);

        switch ($code) {
            case 'FINISHED':
                $mediaId = $publisher->publishContainer($account, $containerId);
                $this->markPublished($target, $mediaId);

                return;

            case 'PUBLISHED':
                // Already published (e.g. a previous attempt crashed after media_publish).
                $this->markPublished($target, $containerId);

                return;

            case 'ERROR':
            case 'EXPIRED':
                $this->markFailed($target, 'Instagram could not process the video ('.$code.'): '.($status['status'] ?? 'no details'));

                return;

            default: // IN_PROGRESS or unknown
                if ($this->attempts() >= $this->maxPolls()) {
                    $this->markFailed($target, 'Instagram processing timed out: the video was not ready after '.$this->maxPolls().' checks.');

                    return;
                }

                $this->release($this->pollInterval());
        }
    }

    /**
     * Called by the queue once retries are exhausted (e.g. repeated HTTP errors).
     */
    public function failed(?\Throwable $e): void
    {
        $target = PostTarget::find($this->targetId);

        if ($target && $target->status === 'processing') {
            $this->markFailed($target, 'Instagram publishing failed: '.($e?->getMessage() ?? 'unknown error'));
        }
    }

    private function markPublished(PostTarget $target, string $mediaId): void
    {
        $target->update([
            'status' => 'published',
            'platform_post_id' => $mediaId,
            'published_at' => now(),
            'error_message' => null,
        ]);

        SchedulePostJob::updateParentPostStatus($target->post_id);

        Log::info("CheckInstagramContainerJob: target {$target->id} published as media {$mediaId}.");
    }

    private function markFailed(PostTarget $target, string $message): void
    {
        $target->update([
            'status' => 'failed',
            'error_message' => mb_substr($message, 0, 500),
        ]);

        SchedulePostJob::updateParentPostStatus($target->post_id);

        Log::warning("CheckInstagramContainerJob: target {$target->id} failed.", ['error' => $message]);
    }

    private function maxPolls(): int
    {
        return max(1, (int) config('services.instagram.poll_max_attempts', 6));
    }

    private function pollInterval(): int
    {
        return max(1, (int) config('services.instagram.poll_interval_seconds', 60));
    }
}
