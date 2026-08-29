<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class BackupDatabase extends Command
{
    protected $signature = 'backup:database {--compress}';

    protected $description = 'Backup the application database';

    public function handle()
    {
        $this->info('Starting database backup...');

        $database = config('database.connections.mysql.database');
        $user = config('database.connections.mysql.username');
        $password = config('database.connections.mysql.password');
        $host = config('database.connections.mysql.host');
        $port = config('database.connections.mysql.port');

        // Create backups directory
        $backupDir = storage_path('backups');
        if (! is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        // Generate backup filename
        $filename = 'backup-'.date('Y-m-d-H-i-s').'.sql';
        $filepath = $backupDir.DIRECTORY_SEPARATOR.$filename;

        // Run mysqldump
        $command = sprintf(
            'mysqldump -h %s -P %s -u %s -p%s %s',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($user),
            escapeshellarg($password),
            escapeshellarg($database)
        );

        try {
            exec($command.' > '.escapeshellarg($filepath).' 2>&1', $output, $exitCode);

            if ($exitCode !== 0 || ! file_exists($filepath) || filesize($filepath) === 0) {
                $this->error('❌ Backup failed — no data or non-zero exit code.');

                return 1;
            }

            $this->info('✅ Database backed up successfully');
            $this->info("File: $filename");
            $this->info('Size: '.$this->formatBytes(filesize($filepath)));

            // Optionally compress
            if ($this->option('compress')) {
                $this->compressBackup($filepath);
            }

            return 0;
        } catch (\Exception $e) {
            $this->error('❌ Backup error: '.$e->getMessage());

            return 1;
        }
    }

    protected function compressBackup($filepath)
    {
        $gzipPath = $filepath.'.gz';

        // Use PHP's native gzip for cross-platform support
        $fp = fopen($filepath, 'rb');
        $gzfp = gzopen($gzipPath, 'wb9');

        while (! feof($fp)) {
            gzwrite($gzfp, fread($fp, 524288)); // 512KB chunks
        }

        fclose($fp);
        gzclose($gzfp);

        if (file_exists($gzipPath) && filesize($gzipPath) > 0) {
            $this->info('✅ Compressed to: '.basename($gzipPath));
            $this->info('Compressed size: '.$this->formatBytes(filesize($gzipPath)));
            unlink($filepath); // Delete original
        }
    }

    protected function formatBytes($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2).' '.$units[$pow];
    }
}
