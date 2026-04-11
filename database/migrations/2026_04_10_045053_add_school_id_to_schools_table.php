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
        Schema::table('schools', function (Blueprint $table) {
            $table->string('school_id')->nullable()->after('id')->unique();
        });

        // Populate existing records
        $schools = DB::table('schools')->orderBy('id')->get();
        foreach ($schools as $index => $school) {
            DB::table('schools')
                ->where('id', $school->id)
                ->update(['school_id' => 'SCH' . str_pad($index + 1, 3, '0', STR_PAD_LEFT)]);
        }
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn('school_id');
        });
    }
};
