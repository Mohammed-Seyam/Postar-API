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
        Schema::table('customers', function (Blueprint $table) {
            $table->string('billable_id', 36)->change();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('billable_id', 36)->change();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->string('billable_id', 36)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedBigInteger('billable_id')->change();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedBigInteger('billable_id')->change();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('billable_id')->change();
        });
    }
};
