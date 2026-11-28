<?php

namespace App\Console\Commands;

use App\Models\PostTarget;
use App\Services\Platforms\TikTokAnalyticsService;
use App\Services\Platforms\TikTokService;
use App\Services\YouTubeAnalyticsService;
use App\Services\YouTubeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class PollVideoAnalytics extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'postar:poll-analytics';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Poll TikTok and YouTube APIs for video engagement metrics of published posts';

    /**
     * Execute the console command.
     */
    public function handle(
        TikTokService $tiktokService,
        TikTokAnalyticsService $tiktokAnalyticsService,
        YouTubeService $youtubeService,
        YouTubeAnalyticsService $youtubeAnalyticsService
    ) {
        $this->info('Starting video analytics polling job...');

        $thirtyDaysAgo = now()->subDays(30);
        $sixHoursAgo = now()->subHours(6);

        // Fetch targets matching conditions:
        // 1. status is 'published'
        // 2. has platform post ID
        // 3. published in last 30 days
        // 4. not updated in last 6 hours
        // 5. user plan has analytics (analytics_days > 0)
        $targets = PostTarget::where('post_targets.status', 'published')
            ->whereNotNull('post_targets.platform_post_id')
            ->where('post_targets.published_at', '>=', $thirtyDaysAgo)
            ->where(function ($query) use ($sixHoursAgo) {
                $query->whereNull('post_targets.analytics_fetched_at')
                    ->orWhere('post_targets.analytics_fetched_at', '<', $sixHoursAgo);
            })
            ->whereHas('post.user.plan', function ($query) {
                $query->where('analytics_days', '>', 0);
            })
            ->whereHas('socialAccount', function ($query) {
                // Only platforms with an analytics service (Instagram insights not supported yet).
                $query->where('is_active', true)
                    ->whereIn('platform', ['tiktok', 'youtube']);
            })
            ->with(['socialAccount', 'post'])
            ->get();

        if ($targets->isEmpty()) {
            $this->info('No eligible posts found for analytics updates.');

            return;
        }

        $this->info("Found {$targets->count()} post target(s) to update. Grouping by social account...");

        // Group by social account to check/refresh token and execute batched requests
        $groupedTargets = $targets->groupBy('social_account_id');

        foreach ($groupedTargets as $socialAccountId => $accountTargets) {
            $account = $accountTargets->first()->socialAccount;
            if (! $account || ! $account->is_active) {
                Log::warning("PollVideoAnalytics: Social account ID {$socialAccountId} is missing or inactive. skipping.");

                continue;
            }

            $platform = $account->platform;
            $this->info("Processing account ID {$socialAccountId} (@{$account->username}) on platform: {$platform}");

            try {
                // Check if token is about to expire within the next hour, and refresh it silently
                $expiresSoon = $account->token_expires_at
                    && now()->addHour()->greaterThanOrEqualTo($account->token_expires_at);

                if ($expiresSoon) {
                    $this->info('  Token expires soon, refreshing...');
                    if ($platform === 'tiktok') {
                        $tiktokService->refreshToken($account);
                    } elseif ($platform === 'youtube') {
                        $youtubeService->refreshToken($account);
                    }
                    $account->refresh();
                }

                // Resolve access token
                $accessToken = '';
                if ($platform === 'tiktok') {
                    $scopes = explode(',', $account->scopes ?? '');
                    $scopes = array_map('trim', $scopes);
                    if (! in_array('video.list', $scopes)) {
                        throw new \Exception("TikTok account is missing the required 'video.list' scope. Please reconnect your account.");
                    }
                    $accessToken = $account->access_token; // Decrypted by Eloquent cast
                } elseif ($platform === 'youtube') {
                    $accessToken = Crypt::decrypt($account->access_token); // Decrypted by Eloquent and Crypt to match double encryption
                }

                if (empty($accessToken)) {
                    throw new \Exception('Access token is empty.');
                }

                // Batch process targets
                if ($platform === 'tiktok') {
                    $chunks = $accountTargets->chunk(20);
                    foreach ($chunks as $chunk) {
                        $tiktokAnalyticsService->fetchAnalyticsBatch($chunk, $accessToken);
                    }
                } elseif ($platform === 'youtube') {
                    $chunks = $accountTargets->chunk(50);
                    foreach ($chunks as $chunk) {
                        $youtubeAnalyticsService->fetchAnalyticsBatch($chunk, $accessToken);
                    }
                }

                $this->info('  ✓ Successfully processed '.$accountTargets->count().' targets.');

            } catch (\Throwable $e) {
                $errorMessage = $e->getMessage();
                Log::error("PollVideoAnalytics: Failed to process targets for account ID {$socialAccountId}", [
                    'error' => $errorMessage,
                ]);
                $this->error("  ✗ Failed to process account ID {$socialAccountId}: {$errorMessage}");

                // Update targets with the error message
                foreach ($accountTargets as $target) {
                    $target->update([
                        'analytics_error' => substr($errorMessage, 0, 500),
                    ]);
                }
            }
        }

        $this->info('Analytics polling job finished.');
    }
}
