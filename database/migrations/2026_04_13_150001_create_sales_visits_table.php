<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_executive_id')->constrained('sales_executives')->cascadeOnDelete();
            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->string('school_name_snapshot')->nullable();
            $table->string('location_label')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamp('visited_at');
            $table->timestamp('check_out_at')->nullable();
            $table->string('purpose')->nullable();
            $table->string('status', 32)->default('completed');
            $table->decimal('distance_km', 10, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['sales_executive_id', 'visited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_visits');
    }
};
