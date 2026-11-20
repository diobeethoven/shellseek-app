<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks rows whose authorization expired before execution and whose
     * identical command was validated again under a new request_id.
     */
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->string('original_request_id', 64)->nullable()->after('request_id');
            $table->boolean('revalidated')->default(false)->after('original_request_id');
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropColumn(['original_request_id', 'revalidated']);
        });
    }
};
