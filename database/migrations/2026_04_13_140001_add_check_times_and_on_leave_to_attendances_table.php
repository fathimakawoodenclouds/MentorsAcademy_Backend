<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->time('check_in')->nullable()->after('status');
            $table->time('check_out')->nullable()->after('check_in');
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE attendances MODIFY COLUMN status ENUM('present','absent','late','half_day','on_leave') NOT NULL");
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE attendances MODIFY COLUMN status ENUM('present','absent','late','half_day') NOT NULL");
        }

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn(['check_in', 'check_out']);
        });
    }
};
