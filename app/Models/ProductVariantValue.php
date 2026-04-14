<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ProductVariantValue extends Model
{
    protected $fillable = [
        'uuid',
        'variant_id',
        'option_value_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (ProductVariantValue $row) {
            if (empty($row->uuid)) {
                $row->uuid = (string) Str::uuid();
            }
        });
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function optionValue()
    {
        return $this->belongsTo(ProductOptionValue::class, 'option_value_id');
    }
}
