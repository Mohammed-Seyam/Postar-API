<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CleanupOldBackups extends Command
{
    protected $signature = 'backups:cleanup {--keep=7}';

    protected $description = 'Remove backups older than specified days';

    public function handle()
    {
        $keep = (int) $this->option('keep');
        $backupPath = storage_path('backups');
        $cutoffTime = time() - ($keep * 24 * 60 * 60);

        if (! is_dir($backupPath)) {
            $this->info('No backups directory found. Nothing to clean.');

            return 0;
        }

        $deleted = 0;

        foreach (glob("$backupPath/*") as $file) {
            if (is_file($file) && filemtime($file) < $cutoffTime) {
                unlink($file);
                $deleted++;
            }
        }

        $this->info("Deleted $deleted old backup file(s) (kept last $keep days).");

        return 0;
    }
}
