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
        // Re-create or alter subscriptions table to support both Cashier and custom operations
        if (! Schema::hasTable('subscriptions')) {
            Schema::create('subscriptions', function (Blueprint $table) {
                $table->id();
                $table->uuid('user_id')->nullable()->index();
                $table->morphs('billable'); // For Cashier compatibility
                $table->string('type')->default('default'); // For Cashier compatibility
                $table->string('paddle_id')->unique(); // For Cashier compatibility (Paddle subscription ID)
                $table->string('paddle_subscription_id', 100)->nullable()->index(); // Custom field
                $table->string('paddle_plan_id', 100)->nullable();
                $table->string('status'); // active, canceled, paused, past_due
                $table->boolean('cancel_at_period_end')->default(false);
                $table->timestamp('cancel_at')->nullable();
                $table->timestamp('current_period_end')->nullable();
                $table->timestamp('trial_ends_at')->nullable(); // For Cashier compatibility
                $table->timestamp('paused_at')->nullable(); // For Cashier compatibility
                $table->timestamp('ends_at')->nullable();
                $table->timestamps();
            });
        } else {
            Schema::table('subscriptions', function (Blueprint $table) {
                if (! Schema::hasColumn('subscriptions', 'user_id')) {
                    $table->uuid('user_id')->nullable()->index();
                }
                if (! Schema::hasColumn('subscriptions', 'paddle_subscription_id')) {
                    $table->string('paddle_subscription_id', 100)->nullable()->index();
                }
                if (! Schema::hasColumn('subscriptions', 'paddle_plan_id')) {
                    $table->string('paddle_plan_id', 100)->nullable();
                }
                if (! Schema::hasColumn('subscriptions', 'cancel_at_period_end')) {
                    $table->boolean('cancel_at_period_end')->default(false);
                }
                if (! Schema::hasColumn('subscriptions', 'cancel_at')) {
                    $table->timestamp('cancel_at')->nullable();
                }
                if (! Schema::hasColumn('subscriptions', 'current_period_end')) {
                    $table->timestamp('current_period_end')->nullable();
                }
            });
        }

        // Create webhook_logs table
        if (! Schema::hasTable('webhook_logs')) {
            Schema::create('webhook_logs', function (Blueprint $table) {
                $table->id();
                $table->string('event_type', 100);
                $table->string('paddle_event_id', 100)->unique();
                $table->json('payload');
                $table->timestamp('received_at')->useCurrent();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
        Schema::dropIfExists('subscriptions');
    }
};
