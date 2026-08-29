<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // For MySQL, modifying an ENUM requires raw SQL or dropping/recreating.
        // A simple raw statement is the most reliable way to add an enum value.
        if (config('database.default') !== 'sqlite') {
            DB::statement("ALTER TABLE videos MODIFY COLUMN status ENUM('draft', 'ready', 'scheduled', 'published', 'failed') DEFAULT 'draft'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (config('database.default') !== 'sqlite') {
            DB::statement("ALTER TABLE videos MODIFY COLUMN status ENUM('draft', 'scheduled', 'published', 'failed') DEFAULT 'draft'");
        }
    }
};
