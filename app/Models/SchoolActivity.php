<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SchoolActivity extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'school_id',
        'activity_id',
        'amount_type',
        'amount_per_unit',
        'quantity',
        'total_amount',
        'start_date',
        'end_date',
        'time_slots',
        'payment_status',
    ];

    protected $casts = [
        'time_slots' => 'array',
        'amount_per_unit' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }
}
