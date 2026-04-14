<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesGpsPing extends Model
{
    protected $fillable = [
        'sales_executive_id',
        'latitude',
        'longitude',
        'accuracy_meters',
        'source',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'recorded_at' => 'datetime',
        ];
    }

    public function salesExecutive(): BelongsTo
    {
        return $this->belongsTo(SalesExecutive::class);
    }
}
