<?php
/*
 * Created At: 2026-04-10
 * This trait auto-generates unique slugs for models on save.
 */

namespace App\Traits;

use Illuminate\Support\Str;

trait HasSlug
{
    public static function bootHasSlug()
    {
        static::creating(function ($model) {
            $model->generateSlug();
        });

        static::updating(function ($model) {
            if ($model->isDirty($model->getSlugSourceColumn())) {
                $model->generateSlug();
            }
        });
    }

    protected function generateSlug()
    {
        $sourceColumn = $this->getSlugSourceColumn();
        $slug = Str::slug($this->$sourceColumn);
        
        $query = static::where('slug', 'like', "{$slug}%")
            ->where('id', '!=', $this->id ?? 0);
            
        if (in_array('Illuminate\Database\Eloquent\SoftDeletes', class_uses_recursive(static::class))) {
            $query->withTrashed();
        }
            
        $count = $query->count();
            
        $this->slug = $count > 0 ? "{$slug}-{$count}" : $slug;
    }

    protected function getSlugSourceColumn(): string
    {
        return 'name';
    }
}
