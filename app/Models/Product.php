<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'brand_id',
        'category_id',
        'name',
        'slug',
        'description',
        'hsn_code',
        'warranty',
        'return_policy_days',
        'thumbnail_media_id',
        'is_featured',
        'has_variants',
        'tags',
        'attributes',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'is_featured' => 'boolean',
            'has_variants' => 'boolean',
            'tags' => 'array',
            'attributes' => 'array',
            'return_policy_days' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Product $product) {
            if (empty($product->uuid)) {
                $product->uuid = (string) Str::uuid();
            }
        });

        static::deleting(function (Product $product) {
            if ($product->isForceDeleting()) {
                return;
            }
            $product->options()->delete();
            $product->variants->each->delete();
            $product->images()->delete();
        });
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function thumbnail()
    {
        return $this->belongsTo(MediaFile::class, 'thumbnail_media_id');
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class)
            ->orderByDesc('is_default')
            ->orderBy('id');
    }

    public function options()
    {
        return $this->hasMany(ProductOption::class);
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function defaultVariant()
    {
        return $this->hasOne(ProductVariant::class)
            ->where('is_default', true)
            ->latest('id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
