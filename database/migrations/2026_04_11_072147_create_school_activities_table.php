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
        Schema::create('school_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->foreignId('activity_id')->constrained('activities')->cascadeOnDelete();
            $table->enum('amount_type', ['per_head', 'per_class', 'per_school'])->default('per_head');
            $table->decimal('amount_per_unit', 10, 2)->default(0);
            $table->integer('quantity')->nullable()->comment('Number of students or classes');
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->json('time_slots')->nullable();
            $table->enum('payment_status', ['pending', 'completed', 'partial'])->default('pending');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('school_activities');
    }
};
