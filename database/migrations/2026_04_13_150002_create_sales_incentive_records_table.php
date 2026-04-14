<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_incentive_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_executive_id')->constrained('sales_executives')->cascadeOnDelete();
            $table->string('product_category', 64);
            $table->unsignedInteger('quantity')->default(0);
            $table->decimal('unit_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->string('earned_month', 7);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['sales_executive_id', 'earned_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_incentive_records');
    }
};
