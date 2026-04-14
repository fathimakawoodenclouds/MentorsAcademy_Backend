<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_gps_pings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_executive_id')->constrained('sales_executives')->cascadeOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedSmallInteger('accuracy_meters')->nullable();
            $table->string('source', 32)->default('mobile_app');
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['sales_executive_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_gps_pings');
    }
};
