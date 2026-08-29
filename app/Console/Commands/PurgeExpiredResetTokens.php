<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgeExpiredResetTokens extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'auth:purge-expired-reset-tokens';

    /**
     * The description of the console command.
     *
     * @var string
     */
    protected $description = 'Purge expired password reset tokens from the database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $deleted = DB::table('password_reset_tokens')
            ->where('created_at', '<', now()->subMinutes(60))
            ->delete();

        $this->info("Successfully purged {$deleted} expired password reset token(s).");
    }
}
