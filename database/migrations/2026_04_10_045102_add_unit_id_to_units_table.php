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
        Schema::table('units', function (Blueprint $table) {
            $table->string('unit_id')->nullable()->after('id')->unique();
        });

        // Populate existing records
        $units = DB::table('units')->orderBy('id')->get();
        foreach ($units as $index => $unit) {
            DB::table('units')
                ->where('id', $unit->id)
                ->update(['unit_id' => 'UNT' . str_pad($index + 1, 3, '0', STR_PAD_LEFT)]);
        }
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn('unit_id');
        });
    }
};
