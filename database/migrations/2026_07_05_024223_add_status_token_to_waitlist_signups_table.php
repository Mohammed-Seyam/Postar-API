<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('waitlist_signups', function (Blueprint $table) {
            $table->string('status_token', 64)->nullable()->unique()->after('referral_code');
        });

        // Backfill existing rows
        $signups = \App\Models\WaitlistSignup::whereNull('status_token')->get();
        foreach ($signups as $signup) {
            $signup->update(['status_token' => \Illuminate\Support\Str::random(32)]);
        }

        // Make it non-nullable after backfill
        Schema::table('waitlist_signups', function (Blueprint $table) {
            $table->string('status_token', 64)->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('waitlist_signups', function (Blueprint $table) {
            $table->dropColumn('status_token');
        });
    }
};
