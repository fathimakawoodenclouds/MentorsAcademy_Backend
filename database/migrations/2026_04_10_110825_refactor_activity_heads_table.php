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
        Schema::table('activity_heads', function (Blueprint $table) {
            $table->dropForeign(['school_id']);
            $table->dropColumn('school_id');
            $table->foreignId('activity_id')->after('user_id')->constrained('activities')->cascadeOnDelete();
            $table->string('slug')->unique()->after('activity_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_heads', function (Blueprint $table) {
            $table->dropColumn(['activity_id', 'slug']);
            $table->foreignId('school_id')->nullable()->constrained('schools')->cascadeOnDelete();
        });
    }
};
