<?php

namespace App\Console\Commands;

use App\Jobs\SchedulePostJob;
use App\Models\PostTarget;
use Illuminate\Console\Command;

class ProcessScheduledPosts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'postar:publish-scheduled';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Find pending scheduled post targets and dispatch publishing jobs';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking for due scheduled posts...');

        $dueTargets = PostTarget::where('status', 'pending')
            ->whereHas('post', function ($query) {
                $query->where('status', 'scheduled')
                    ->whereNotNull('scheduled_at')
                    ->where('scheduled_at', '<=', now());
            })
            ->get();

        if ($dueTargets->isEmpty()) {
            $this->info('No pending posts due for publishing.');

            return;
        }

        $this->info("Found {$dueTargets->count()} target(s). Dispatching jobs...");

        foreach ($dueTargets as $target) {
            // Dispatch without delay; it's already due.
            SchedulePostJob::dispatch($target->id);
            $this->info("Dispatched job for target ID: {$target->id}");
        }

        $this->info('Done.');
    }
}
