<?php

namespace App\Traits;

use Illuminate\Support\Facades\DB;

trait HasReadableId
{
    public static function bootHasReadableId()
    {
        static::creating(function ($model) {
            $model->generateReadableId();
        });
    }

    public function generateReadableId()
    {
        $config = $this->getReadableIdConfig();
        $prefix = $config['prefix'];
        $column = $config['column'];
        $useYear = $config['use_year'] ?? false;
        
        $year = date('Y');
        $searchPrefix = $useYear ? "{$prefix}{$year}" : $prefix;
        
        $query = static::where($column, 'like', "{$searchPrefix}%")
            ->orderBy($column, 'desc');
        
        // Include soft-deleted records to avoid duplicate IDs
        if (in_array('Illuminate\Database\Eloquent\SoftDeletes', class_uses_recursive(static::class))) {
            $query->withTrashed();
        }
        
        $lastRecord = $query->first();

        if ($lastRecord) {
            $lastId = $lastRecord->$column;
            $sequence = (int) substr($lastId, strlen($searchPrefix));
            $newSequence = $sequence + 1;
        } else {
            $newSequence = 1;
        }

        $this->$column = $searchPrefix . str_pad($newSequence, 3, '0', STR_PAD_LEFT);
    }

    abstract protected function getReadableIdConfig(): array;
}
