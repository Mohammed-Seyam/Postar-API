<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            if (! Schema::hasColumn('uploads', 'content_type')) {
                $table->string('content_type')->nullable()->after('key');
            }
            if (! Schema::hasColumn('uploads', 'expected_size')) {
                $table->unsignedBigInteger('expected_size')->nullable()->after('content_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            $table->dropColumn(['content_type', 'expected_size']);
        });
    }
};
