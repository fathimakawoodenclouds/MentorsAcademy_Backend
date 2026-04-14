<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ProductVariant extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'product_id',
        'sku',
        'name',
        'barcode',
        'mrp',
        'selling_price',
        'cost_price',
        'stock_qty',
        'min_order_qty',
        'max_order_qty',
        'weight',
        'length_cm',
        'width_cm',
        'height_cm',
        'tax_percentage',
        'tax_inclusive',
        'image_media_id',
        'is_default',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'mrp' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'stock_qty' => 'integer',
            'min_order_qty' => 'integer',
            'max_order_qty' => 'integer',
            'weight' => 'decimal:2',
            'length_cm' => 'decimal:2',
            'width_cm' => 'decimal:2',
            'height_cm' => 'decimal:2',
            'tax_percentage' => 'decimal:2',
            'tax_inclusive' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function image()
    {
        return $this->belongsTo(MediaFile::class, 'image_media_id');
    }

    public function media()
    {
        return $this->image();
    }

    public function variantValues()
    {
        return $this->hasMany(ProductVariantValue::class, 'variant_id');
    }

    public function values()
    {
        return $this->variantValues();
    }

    public function hasDiscount(): bool
    {
        return (float) $this->mrp > 0 && (float) $this->selling_price > 0 && (float) $this->mrp > (float) $this->selling_price;
    }

    public function getDiscountPercentage(): float
    {
        if (! $this->hasDiscount()) {
            return 0;
        }

        return round(((float) $this->mrp - (float) $this->selling_price) / (float) $this->mrp * 100, 2);
    }

    public function getDiscountAmount(): float
    {
        if (! $this->hasDiscount()) {
            return 0;
        }

        return round((float) $this->mrp - (float) $this->selling_price, 2);
    }

    public function getDisplayName(?Product $product = null): string
    {
        if (! empty($this->name)) {
            return $this->name;
        }

        $productName = ($product ?? $this->product)?->name ?? '';
        $optionValues = $this->relationLoaded('variantValues')
            ? $this->variantValues->map(function (ProductVariantValue $vv) {
                return $vv->relationLoaded('optionValue')
                    ? ($vv->optionValue->value ?? '')
                    : '';
            })->filter()->implode(' - ')
            : '';

        if ($optionValues !== '') {
            return $productName !== '' ? $productName.' - '.$optionValues : $optionValues;
        }

        return $productName !== '' ? $productName : (string) $this->sku;
    }

    protected static function booted(): void
    {
        static::creating(function (ProductVariant $variant) {
            if (empty($variant->uuid)) {
                $variant->uuid = (string) Str::uuid();
            }
        });

        static::deleting(function (ProductVariant $variant) {
            if ($variant->isForceDeleting()) {
                return;
            }
            $variant->variantValues()->delete();
        });
    }
}
