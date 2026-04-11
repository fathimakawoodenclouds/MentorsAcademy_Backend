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
            $table->string('staff_id')->nullable()->after('id')->unique();
        });

        // Populate existing records
        $coaches = DB::table('coaches')->orderBy('id')->get();
        $year = date('Y');
        foreach ($coaches as $index => $coach) {
            DB::table('coaches')
                ->where('id', $coach->id)
                ->update(['staff_id' => "CH-{$year}-" . str_pad($index + 1, 3, '0', STR_PAD_LEFT)]);
        }
    }

    public function down(): void
    {
        Schema::table('coaches', function (Blueprint $table) {
            $table->dropColumn('staff_id');
        });
    }
};
