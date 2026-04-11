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
        if (Schema::hasColumn('units', 'district')) {
            Schema::table('units', function (Blueprint $table) {
                $table->renameColumn('district', 'city');
            });
        }

        if (Schema::hasTable('units') && !Schema::hasColumn('units', 'state')) {
            Schema::table('units', function (Blueprint $table) {
                $table->string('state')->nullable()->after('city');
            });
        }

        if (Schema::hasColumn('schools', 'district')) {
            Schema::table('schools', function (Blueprint $table) {
                $table->renameColumn('district', 'city');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('units', 'city')) {
            Schema::table('units', function (Blueprint $table) {
                $table->renameColumn('city', 'district');
            });
        }

        if (Schema::hasColumn('units', 'state')) {
            Schema::table('units', function (Blueprint $table) {
                $table->dropColumn('state');
            });
        }

        if (Schema::hasColumn('schools', 'city')) {
            Schema::table('schools', function (Blueprint $table) {
                $table->renameColumn('city', 'district');
            });
        }
    }
};
