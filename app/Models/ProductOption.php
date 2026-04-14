<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ProductOption extends Model
{
    protected $fillable = [
        'uuid',
        'product_id',
        'name',
    ];

    protected static function booted(): void
    {
        static::creating(function (ProductOption $option) {
            if (empty($option->uuid)) {
                $option->uuid = (string) Str::uuid();
            }
        });
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function values()
    {
        return $this->hasMany(ProductOptionValue::class, 'product_option_id');
    }
}
