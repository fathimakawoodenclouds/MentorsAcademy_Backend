<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesVisit extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'sales_executive_id',
        'school_id',
        'school_name_snapshot',
        'location_label',
        'latitude',
        'longitude',
        'visited_at',
        'check_out_at',
        'purpose',
        'status',
        'distance_km',
    ];

    protected function casts(): array
    {
        return [
            'visited_at' => 'datetime',
            'check_out_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function salesExecutive(): BelongsTo
    {
        return $this->belongsTo(SalesExecutive::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
