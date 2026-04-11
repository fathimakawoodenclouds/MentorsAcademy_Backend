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
        Schema::table('staff_profiles', function (Blueprint $table) {
            $table->integer('age')->nullable()->after('phone');
            $table->enum('gender', ['male', 'female', 'other'])->nullable()->after('age');
            $table->string('alternate_phone')->nullable()->after('gender');
            $table->string('state')->nullable()->after('address');
            $table->string('city')->nullable()->after('state');
            $table->string('pincode', 10)->nullable()->after('city');
            $table->enum('wage_type', ['Monthly', 'Weekly', 'Daily'])->nullable()->after('salary');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('staff_profiles', function (Blueprint $table) {
            //
        });
    }
};
