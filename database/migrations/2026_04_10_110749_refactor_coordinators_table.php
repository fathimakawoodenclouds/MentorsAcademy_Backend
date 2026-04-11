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
        Schema::table('coordinators', function (Blueprint $table) {
            $table->dropForeign(['school_id']);
            $table->dropColumn('school_id');
            $table->foreignId('unit_id')->after('user_id')->constrained('units')->cascadeOnDelete();
            $table->string('staff_id')->unique()->after('id');
            $table->string('slug')->unique()->after('unit_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('coordinators', function (Blueprint $table) {
            $table->dropColumn(['staff_id', 'slug', 'unit_id']);
            $table->foreignId('school_id')->nullable()->constrained('schools')->cascadeOnDelete();
        });
    }
};
