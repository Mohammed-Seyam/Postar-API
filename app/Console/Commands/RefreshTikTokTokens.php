<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Services\Platforms\TikTokService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RefreshTikTokTokens extends Command
{
    protected $signature = 'tiktok:refresh-tokens';

    protected $description = 'Refresh TikTok access tokens that expire within the next 2 hours';

    public function handle(TikTokService $tiktok): void
    {
        // Find tokens expiring within 2 hours
        $accounts = SocialAccount::where('platform', 'tiktok')
            ->where('token_expires_at', '<=', now()->addHours(2))
            ->where('refresh_expires_at', '>', now()) // refresh token still valid
            ->get();

        $this->info("Refreshing {$accounts->count()} TikTok token(s)...");

        foreach ($accounts as $account) {
            try {
                $tiktok->refreshToken($account);
                $this->line("  ✓ Refreshed account ID {$account->id} (user {$account->user_id})");
            } catch (\Throwable $e) {
                Log::error("TikTok token refresh failed for account {$account->id}: ".$e->getMessage());
                $this->error("  ✗ Failed account ID {$account->id}: ".$e->getMessage());
            }
        }

        $this->info('Done.');
    }
}
