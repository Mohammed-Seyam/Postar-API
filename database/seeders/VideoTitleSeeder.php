<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class VideoTitleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $videos = \App\Models\Video::whereNull('title')->get();
        foreach ($videos as $video) {
            $basename = basename($video->file_path);
            $nameWithoutExtension = pathinfo($basename, PATHINFO_FILENAME);

            // The format is usually time() . '_' . $sanitizedOriginalName
            // We want to remove the timestamp prefix if it's there
            $title = preg_replace('/^\d{10}_/', '', $nameWithoutExtension);

            // If it still has an underscore we can replace it with space, or leave it.
            // Leaving it exactly as original sanitized string is usually safe.

            $video->update(['title' => $title]);
        }
    }
}
