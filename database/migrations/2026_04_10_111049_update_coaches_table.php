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
        Schema::table('coaches', function (Blueprint $table) {
            $table->foreignId('activity_id')->after('activity_head_id')->nullable()->constrained('activities')->nullOnDelete();
            $table->string('slug')->unique()->after('specialization')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('coaches', function (Blueprint $table) {
            $table->dropForeign(['activity_id']);
            $table->dropColumn(['activity_id', 'slug']);
        });
    }
};
