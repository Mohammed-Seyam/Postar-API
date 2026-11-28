<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Services\Platforms\InstagramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RefreshInstagramTokens extends Command
{
    protected $signature = 'instagram:refresh-tokens {--days=10 : Refresh tokens expiring within this many days}';

    protected $description = 'Refresh long-lived Instagram access tokens before they expire (60-day tokens)';

    public function handle(InstagramService $instagram): void
    {
        $days = max(1, (int) $this->option('days'));

        // Long-lived tokens can only be refreshed while still valid and at least
        // 24h old; a 60-day token expiring within 10 days is ~50 days old.
        $accounts = SocialAccount::where('platform', 'instagram')
            ->where('is_active', true)
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '>', now())
            ->where('token_expires_at', '<=', now()->addDays($days))
            ->get();

        $this->info("Refreshing {$accounts->count()} Instagram token(s)...");

        foreach ($accounts as $account) {
            try {
                $instagram->refreshToken($account);
                $this->line("  ✓ Refreshed account ID {$account->id} (user {$account->user_id})");
            } catch (\Throwable $e) {
                Log::error("Instagram token refresh failed for account {$account->id}: ".$e->getMessage());
                $this->error("  ✗ Failed account ID {$account->id}: ".$e->getMessage());
            }
        }

        $this->info('Done.');
    }
}
