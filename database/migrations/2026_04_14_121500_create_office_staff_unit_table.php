<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_staff_unit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_staff_id')->constrained('office_staff')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('units')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['office_staff_id', 'unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_staff_unit');
    }
};
