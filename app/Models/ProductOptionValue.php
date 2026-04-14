<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ProductOptionValue extends Model
{
    protected $fillable = [
        'uuid',
        'product_option_id',
        'value',
    ];

    protected static function booted(): void
    {
        static::creating(function (ProductOptionValue $row) {
            if (empty($row->uuid)) {
                $row->uuid = (string) Str::uuid();
            }
        });
    }

    public function productOption()
    {
        return $this->belongsTo(ProductOption::class, 'product_option_id');
    }
}
