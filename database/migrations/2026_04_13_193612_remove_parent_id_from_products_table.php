<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('parent_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('hsn_code')->nullable()->after('description');
            $table->string('warranty')->nullable()->after('hsn_code');
            $table->integer('return_policy_days')->nullable()->after('warranty');
            $table->boolean('has_variants')->default(false)->after('is_featured');
        });

        $now = now();
        foreach (DB::table('products')->whereNull('deleted_at')->cursor() as $p) {
            $exists = DB::table('product_variants')->where('product_id', $p->id)->exists();
            if ($exists) {
                continue;
            }

            DB::table('product_variants')->insert([
                'uuid' => (string) Str::uuid(),
                'product_id' => $p->id,
                'sku' => 'SKU-'.$p->id.'-'.Str::lower(Str::random(8)),
                'name' => null,
                'barcode' => null,
                'mrp' => 0,
                'selling_price' => 0,
                'cost_price' => null,
                'stock_qty' => 0,
                'min_order_qty' => null,
                'max_order_qty' => null,
                'weight' => null,
                'length_cm' => null,
                'width_cm' => null,
                'height_cm' => null,
                'tax_percentage' => null,
                'tax_inclusive' => true,
                'image_media_id' => null,
                'is_default' => true,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['hsn_code', 'warranty', 'return_policy_days', 'has_variants']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->constrained('products')->nullOnDelete();
        });
    }
};
