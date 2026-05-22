<?php

namespace App\Console\Commands;

use App\Models\Upload;
use Illuminate\Console\Command;

class CleanupExpiredUploads extends Command
{
    protected $signature = 'uploads:cleanup-expired';
    protected $description = 'Clean up expired upload records';

    public function handle()
    {
        $count = Upload::where('expires_at', '<', now())
            ->where('status', 'pending')
            ->delete();

        $this->info("Deleted $count expired upload(s).");
    }
}
