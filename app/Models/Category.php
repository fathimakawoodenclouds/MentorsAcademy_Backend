<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Category extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'brand_id',
        'name',
        'slug',
        'image_media_id',
        'parent_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Category $category) {
            if (empty($category->uuid)) {
                $category->uuid = (string) Str::uuid();
            }
        });
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function image()
    {
        return $this->belongsTo(MediaFile::class, 'image_media_id');
    }

    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function childrenRecursive()
    {
        return $this->children()->with('childrenRecursive');
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    /**
     * @return array<int, string>
     */
    public function ancestorNames(): array
    {
        $names = [];
        $current = $this->relationLoaded('parent')
            ? $this->parent
            : $this->parent()->first();

        $guard = 0;
        while ($current && $guard++ < 64) {
            array_unshift($names, $current->name);
            $current = $current->parent()->first();
        }

        return $names;
    }

    public function hierarchyLabel(): string
    {
        $parts = $this->ancestorNames();
        $parts[] = $this->name;

        return implode(' > ', $parts);
    }
}
