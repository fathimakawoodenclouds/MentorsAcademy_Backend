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
            $table->string('staff_id')->nullable()->after('id')->unique();
        });

        // Populate existing records
        $heads = DB::table('activity_heads')->orderBy('id')->get();
        $year = date('Y');
        foreach ($heads as $index => $head) {
            DB::table('activity_heads')
                ->where('id', $head->id)
                ->update(['staff_id' => "AH-{$year}-" . str_pad($index + 1, 3, '0', STR_PAD_LEFT)]);
        }
    }

    public function down(): void
    {
        Schema::table('activity_heads', function (Blueprint $table) {
            $table->dropColumn('staff_id');
        });
    }
};
