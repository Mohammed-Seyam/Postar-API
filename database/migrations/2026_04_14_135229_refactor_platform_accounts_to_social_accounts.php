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
        Schema::rename('platform_accounts', 'social_accounts');

        Schema::table('social_accounts', function (Blueprint $table) {
            $table->renameColumn('external_account_id', 'platform_user_id');
            
            $table->string('username')->nullable();
            $table->string('account_name')->nullable();
            $table->string('avatar_url', 512)->nullable();
            $table->text('page_token')->nullable();
            $table->timestamp('refresh_expires_at')->nullable();
            $table->text('scopes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['username', 'account_name', 'avatar_url', 'page_token', 'refresh_expires_at', 'scopes', 'is_active']);
            $table->renameColumn('platform_user_id', 'external_account_id');
        });

        Schema::rename('social_accounts', 'platform_accounts');
    }
};
