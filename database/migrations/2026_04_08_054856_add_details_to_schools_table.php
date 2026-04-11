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
            $table->string('email')->nullable()->after('name');
            $table->string('phone')->nullable()->after('email');
            $table->string('location')->nullable()->after('phone');
            $table->string('district')->nullable()->after('location');
            $table->string('state')->nullable()->after('district');
            $table->string('pincode')->nullable()->after('state');
            $table->string('academic_year')->nullable()->after('pincode');
            $table->string('status')->default('ACTIVE')->after('address');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['email', 'phone', 'location', 'district', 'state', 'pincode', 'academic_year', 'status']);
        });
    }
};
